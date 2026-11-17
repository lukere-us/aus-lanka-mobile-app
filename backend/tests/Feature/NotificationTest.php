<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Models\DeviceToken;
use App\Modules\Identity\Models\Role;
use App\Modules\Movies\Models\RentalPackage;
use App\Modules\Notifications\Messages\EventChanged;
use App\Modules\Notifications\Messages\EventReminder;
use App\Modules\Notifications\Messages\OrderReceipt;
use App\Modules\Notifications\Messages\RentalExpiringSoon;
use App\Modules\Notifications\Messages\TicketsReady;
use App\Modules\Notifications\Push\FcmPushSender;
use App\Modules\Notifications\Push\PushSender;
use App\Modules\Orders\Models\Order;
use App\Modules\Rentals\Models\Rental;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\BuildsEvents;
use Tests\Support\BuildsOrders;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use BuildsEvents, BuildsOrders, RefreshDatabase;

    /** @var list<array{token: string, title: string, body: string, data: array}> */
    private array $pushes = [];

    private array $deadTokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
        $test = $this;
        $this->app->instance(PushSender::class, new class($test) implements PushSender
        {
            public function __construct(private NotificationTest $test) {}

            public function send(string $token, string $title, string $body, array $data = []): string
            {
                return $this->test->recordPush($token, $title, $body, $data);
            }
        });
        DeviceToken::create(['user_id' => $this->customer->id, 'token' => 'fcm-phone', 'platform' => 'android']);
    }

    public function recordPush(string $token, string $title, string $body, array $data): string
    {
        $this->pushes[] = compact('token', 'title', 'body', 'data');

        return in_array($token, $this->deadTokens, true) ? PushSender::INVALID_TOKEN : PushSender::SENT;
    }

    private function pay(string $reference): Order
    {
        $order = Order::where('reference', $reference)->with('latestPayment')->firstOrFail();
        $this->providerSends($order, 'succeeded')->assertOk();

        return $order;
    }

    public function test_paying_for_tickets_sends_receipt_email_push_and_inbox_entries(): void
    {
        $order = $this->pay($this->buyTickets($this->publishedEvent(), 2)->json('data.reference'));

        $this->assertCount(1, $this->pushes);
        $this->assertSame('Your tickets are ready', $this->pushes[0]['title']);
        $this->assertSame(['type' => 'tickets_ready', 'order' => $order->reference], $this->pushes[0]['data']);
        $this->assertStringContainsString('2 tickets for Live at Nelum Pokuna', $this->pushes[0]['body']);

        $inbox = $this->actingAs($this->customer)->getJson('/api/v1/me/notifications')->assertOk()
            ->assertJsonPath('meta.unread', 2)->json('data');
        $this->assertEqualsCanonicalizing(['order_paid', 'tickets_ready'], array_column($inbox, 'type'));
        $this->assertNotNull(DeviceToken::first()->last_seen_at);
    }

    public function test_receipt_email_itemises_the_order(): void
    {
        Notification::fake();
        $order = $this->pay($this->buyTickets($this->publishedEvent(), 2)->json('data.reference'));

        Notification::assertSentTo($this->customer, OrderReceipt::class, function (OrderReceipt $n, array $channels) use ($order) {
            $mail = collect($n->toMail($this->customer->fresh())->introLines)->implode("\n");

            return in_array('mail', $channels, true)
                && str_contains($mail, $order->reference)
                && str_contains($mail, '2 × Live at Nelum Pokuna — General — AUD 5000.00')
                && str_contains($mail, 'Total: AUD 5000.00');
        });
        Notification::assertSentTo($this->customer, TicketsReady::class, fn ($n, $channels) => ! in_array('mail', $channels, true));
    }

    public function test_rental_ready_push(): void
    {
        $this->seed(DemoContentSeeder::class);
        $this->pay($this->checkout([['type' => 'rental_package', 'id' => RentalPackage::value('id'), 'quantity' => 1]])->json('data.reference'));

        $this->assertSame('Big Buck Bunny (demo) is ready to watch', $this->pushes[0]['title']);
        $this->assertStringContainsString('48 hours', $this->pushes[0]['body']);
    }

    public function test_push_preference_off_still_fills_the_inbox(): void
    {
        $this->customer->update(['notification_prefs' => ['push' => false, 'email_reminders' => true]]);

        $this->pay($this->buyTickets($this->publishedEvent(), 1)->json('data.reference'));

        $this->assertCount(0, $this->pushes);
        $this->actingAs($this->customer)->getJson('/api/v1/me/notifications')->assertJsonPath('meta.unread', 2);
    }

    public function test_dead_device_tokens_are_forgotten(): void
    {
        $this->deadTokens = ['fcm-phone'];

        $this->pay($this->buyTickets($this->publishedEvent(), 1)->json('data.reference'));

        $this->assertSame(0, DeviceToken::count());
    }

    public function test_inbox_mark_read(): void
    {
        $this->pay($this->buyTickets($this->publishedEvent(), 1)->json('data.reference'));
        $first = $this->actingAs($this->customer)->getJson('/api/v1/me/notifications')->json('data.0.id');

        $this->actingAs($this->customer)->postJson('/api/v1/me/notifications/read', ['ids' => [$first]])->assertOk()->assertJsonPath('data.unread', 1);
        $this->actingAs($this->customer)->postJson('/api/v1/me/notifications/read')->assertOk()->assertJsonPath('data.unread', 0);
    }

    public function test_rental_expiry_and_event_reminders_are_sent_once(): void
    {
        Notification::fake();
        $this->seed(DemoContentSeeder::class);
        $this->pay($this->checkout([['type' => 'rental_package', 'id' => RentalPackage::value('id'), 'quantity' => 1]])->json('data.reference'));
        $this->actingAs($this->customer)->postJson('/api/v1/rentals/'.Rental::value('id').'/play')->assertOk();
        $type = $this->publishedEvent();
        $this->pay($this->buyTickets($type, 2)->json('data.reference'));

        $this->travel(46)->hours(); // rental: ~2 h left
        $this->artisan('notifications:reminders')->assertSuccessful();
        $this->artisan('notifications:reminders')->assertSuccessful();
        Notification::assertSentToTimes($this->customer, RentalExpiringSoon::class, 1);

        $this->travel(28)->days(); // 46 h + 28 days in: the event starts in ~2 h
        $this->artisan('notifications:reminders');
        $this->artisan('notifications:reminders');
        Notification::assertSentToTimes($this->customer, EventReminder::class, 1);
    }

    public function test_reminder_emails_respect_the_preference(): void
    {
        Notification::fake();
        $this->customer->update(['notification_prefs' => ['push' => true, 'email_reminders' => false]]);
        $this->pay($this->buyTickets($this->publishedEvent(), 1)->json('data.reference'));
        $this->travel(29)->days();

        $this->artisan('notifications:reminders');

        Notification::assertSentTo($this->customer, EventReminder::class, fn ($n, $channels) => ! in_array('mail', $channels, true) && in_array('database', $channels, true));
    }

    public function test_holders_hear_about_date_or_venue_changes_but_not_typo_fixes(): void
    {
        Notification::fake();
        $type = $this->publishedEvent();
        $this->pay($this->buyTickets($type, 1)->json('data.reference'));
        $owner = $type->event->organizer->owner;

        $this->actingAs($owner)->patchJson("/api/v1/organizer/events/{$type->event_id}", ['title' => 'Live at Nelum Pokuna!'])->assertOk();
        Notification::assertNotSentTo($this->customer, EventChanged::class);

        $this->actingAs($owner)->patchJson("/api/v1/organizer/events/{$type->event_id}", ['starts_at' => now()->addDays(31)->toIso8601String()])->assertOk();
        Notification::assertSentTo($this->customer, EventChanged::class, fn ($n, $channels) => in_array('mail', $channels, true));
    }

    public function test_cancelled_event_holders_get_cancellation_and_refund_notices(): void
    {
        $type = $this->publishedEvent();
        $this->pay($this->buyTickets($type, 1)->json('data.reference'));
        $this->pushes = [];

        $this->actingAs($this->userWithRole(Role::EVENT_MANAGER))->postJson("/api/v1/admin/events/{$type->event_id}/cancel", ['reason' => 'Venue flooded'])->assertStatus(202);

        $this->assertSame(['Cancelled: Live at Nelum Pokuna', 'Refund processed'], array_column($this->pushes, 'title'));
    }

    public function test_deleted_accounts_receive_nothing(): void
    {
        $this->customer->forceFill(['status' => User::STATUS_DELETED])->save();

        $this->customer->notify(new EventReminder($this->publishedEvent()->event_id));

        $this->assertCount(0, $this->pushes);
        $this->assertSame(0, $this->customer->notifications()->count());
    }

    public function test_fcm_sender_authenticates_with_a_service_account_and_detects_dead_tokens(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $path = tempnam(sys_get_temp_dir(), 'fcm');
        file_put_contents($path, json_encode(['project_id' => 'aus-lanka-test', 'client_email' => 'push@aus-lanka-test.iam.gserviceaccount.com',
            'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']));

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            'fcm.googleapis.com/v1/projects/aus-lanka-test/messages:send' => Http::sequence()
                ->push(['name' => 'projects/aus-lanka-test/messages/1'])
                ->push(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
        ]);
        $sender = new FcmPushSender(['credentials' => $path]);

        $this->assertSame(PushSender::SENT, $sender->send('tok-1', 'Hi', 'Body', ['order' => 'EM-1']));
        $this->assertSame(PushSender::INVALID_TOKEN, $sender->send('tok-2', 'Hi', 'Body'));

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), 'oauth2')) {
                return false;
            }
            [$h, $p] = explode('.', $r['assertion']);
            $claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);

            return $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && $claims['scope'] === 'https://www.googleapis.com/auth/firebase.messaging';
        });
        Http::assertSent(fn ($r) => str_contains($r->url(), 'messages:send') && $r->hasHeader('Authorization', 'Bearer ya29.test')
            && $r['message']['token'] === 'tok-1' && $r['message']['data']['order'] === 'EM-1');
        Http::assertSentCount(3); // token fetched once, then cached
        @unlink($path);
    }
}
