<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Models\DeviceToken;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Notifications\VerificationCodeNotification;
use App\Modules\Identity\Sms\SmsSender;
use App\Modules\Orders\Models\Order;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\BuildsEvents;
use Tests\Support\BuildsOrders;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use BuildsEvents, BuildsOrders, RefreshDatabase;

    /** @var list<array{0: string, 1: string}> */
    private array $sms = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
        Notification::fake();
        $this->app->instance(SmsSender::class, new class($this->sms) implements SmsSender
        {
            public function __construct(private array &$sent) {}

            public function send(string $e164Number, string $message): void
            {
                $this->sent[] = [$e164Number, $message];
            }
        });
    }

    private function lastEmailCode(User $user): string
    {
        $code = null;
        Notification::assertSentTo($user, VerificationCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });

        return $code;
    }

    private function newCustomer(): User
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Amaya Silva', 'email' => 'amaya@example.com', 'password' => 'Secure-Pass123',
            'password_confirmation' => 'Secure-Pass123', 'device_name' => 'Pixel',
        ])->assertCreated()->assertJsonMissingPath('data.debug_code'); // codes are never returned outside local dev

        return User::where('email', 'amaya@example.com')->firstOrFail();
    }

    public function test_registration_emails_a_code_and_the_code_verifies_the_email(): void
    {
        $user = $this->newCustomer();
        $code = $this->lastEmailCode($user);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertNull($user->email_verified_at);

        $this->actingAs($user)->postJson('/api/v1/me/verify/email', ['code' => $code])->assertOk()->assertJsonPath('data.email_verified', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.email_verified']);
        $this->assertDatabaseMissing('verification_codes', ['code_hash' => $code]); // only a hash is stored
    }

    public function test_unverified_email_cannot_buy(): void
    {
        $user = $this->newCustomer();

        $this->checkout(as: $user)->assertForbidden()->assertJsonPath('error.code', 'email_not_verified');

        $this->actingAs($user)->postJson('/api/v1/me/verify/email', ['code' => $this->lastEmailCode($user)]);
        $this->checkout(as: $user->fresh())->assertCreated();
    }

    public function test_wrong_guesses_are_limited_and_codes_expire(): void
    {
        $user = $this->newCustomer();
        $code = $this->lastEmailCode($user);
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->postJson('/api/v1/me/verify/email', ['code' => $wrong])->assertUnprocessable()->assertJsonPath('error.code', 'code_invalid');
        }
        // After 5 wrong guesses even the right code no longer works.
        $this->actingAs($user)->postJson('/api/v1/me/verify/email', ['code' => $code])->assertUnprocessable()->assertJsonPath('error.code', 'code_expired');

        $this->travel(2)->minutes();
        $this->actingAs($user)->postJson('/api/v1/me/verify/email/send')->assertStatus(202);
        $fresh = null;
        Notification::assertSentTo($user, VerificationCodeNotification::class, function ($n) use (&$fresh) {
            $fresh = $n->code;

            return true;
        });
        $this->travel(11)->minutes();
        $this->actingAs($user)->postJson('/api/v1/me/verify/email', ['code' => $fresh])->assertUnprocessable()->assertJsonPath('error.code', 'code_expired');
    }

    public function test_resend_has_a_cooldown(): void
    {
        $user = $this->newCustomer();

        $this->actingAs($user)->postJson('/api/v1/me/verify/email/send')->assertStatus(429)->assertJsonPath('error.code', 'code_recently_sent');
        $this->travel(61)->seconds();
        $this->actingAs($user)->postJson('/api/v1/me/verify/email/send')->assertStatus(202);
    }

    public function test_phone_is_verified_by_sms_and_changing_it_needs_reverification(): void
    {
        $user = $this->customer;
        $this->actingAs($user)->postJson('/api/v1/me/verify/sms/send')->assertUnprocessable()->assertJsonPath('error.code', 'no_phone');

        $this->actingAs($user)->patchJson('/api/v1/me', ['phone' => '+61412345678'])->assertOk();
        $this->actingAs($user)->postJson('/api/v1/me/verify/sms/send')->assertStatus(202);
        $this->assertSame('+61412345678', $this->sms[0][0]);
        preg_match('/\b(\d{6})\b/', $this->sms[0][1], $m);
        $this->actingAs($user)->postJson('/api/v1/me/verify/sms', ['code' => $m[1]])->assertOk()->assertJsonPath('data.phone_verified', true);

        $this->actingAs($user)->patchJson('/api/v1/me', ['phone' => '+61498765432'])->assertOk()->assertJsonPath('data.phone_verified', false);
    }

    public function test_profile_and_preferences_update(): void
    {
        $this->actingAs($this->customer)->patchJson('/api/v1/me', [
            'name' => 'Kasun Perera', 'preferred_currency' => 'AUD', 'marketing_opt_in' => true,
            'notification_prefs' => ['push' => false, 'email_reminders' => true],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Kasun Perera')
            ->assertJsonPath('data.preferred_currency', 'AUD')
            ->assertJsonPath('data.notification_prefs.push', false);

        $this->actingAs($this->customer)->patchJson('/api/v1/me', ['preferred_currency' => 'LKR'])->assertUnprocessable(); // not active yet
        $this->actingAs($this->customer)->patchJson('/api/v1/me', ['status' => 'suspended', 'email' => 'x@y.z'])->assertOk();
        $this->assertSame('active', $this->customer->fresh()->status); // not editable here
    }

    public function test_email_change_needs_password_and_reverification(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->customer)->putJson('/api/v1/me/email', ['email' => 'new@example.com', 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password'], 'error.details');
        $this->actingAs($this->customer)->putJson('/api/v1/me/email', ['email' => 'taken@example.com', 'password' => 'Password123'])
            ->assertUnprocessable();
        $this->actingAs($this->customer)->putJson('/api/v1/me/email', ['email' => 'New@Example.com', 'password' => 'Password123'])->assertOk();

        $fresh = $this->customer->fresh();
        $this->assertSame('new@example.com', $fresh->email);
        $this->assertNull($fresh->email_verified_at);
        Notification::assertSentTo($fresh, VerificationCodeNotification::class);
    }

    public function test_password_change_signs_out_other_devices_only(): void
    {
        $phone = $this->customer->createToken('phone')->plainTextToken;
        $tablet = $this->customer->createToken('tablet')->plainTextToken;

        $this->withToken($phone)->putJson('/api/v1/me/password', [
            'current_password' => 'Password123', 'password' => 'New-Password-99', 'password_confirmation' => 'New-Password-99',
        ])->assertOk()->assertJsonPath('data.other_sessions_signed_out', 1);
        $this->resetAuth();

        $this->withToken($phone)->getJson('/api/v1/me')->assertOk();
        $this->resetAuth();
        $this->withToken($tablet)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_sessions_can_be_listed_and_revoked(): void
    {
        $this->customer->createToken('old laptop');
        $phone = $this->customer->createToken('phone')->plainTextToken;

        $sessions = $this->withToken($phone)->getJson('/api/v1/me/sessions')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $laptop = collect($sessions)->firstWhere('device', 'old laptop');
        $this->withToken($phone)->deleteJson("/api/v1/me/sessions/{$laptop['id']}")->assertNoContent();
        $this->assertSame(1, $this->customer->tokens()->count());
    }

    public function test_push_device_tokens_register_move_between_accounts_and_remove(): void
    {
        $this->actingAs($this->customer)->postJson('/api/v1/me/devices', ['token' => 'fcm-abc', 'platform' => 'android', 'device_name' => 'Pixel 8'])->assertCreated();
        $other = $this->userWithRole(Role::CUSTOMER);
        $this->actingAs($other)->postJson('/api/v1/me/devices', ['token' => 'fcm-abc', 'platform' => 'android'])->assertCreated();

        $this->assertSame($other->id, DeviceToken::where('token', 'fcm-abc')->value('user_id'));
        $this->actingAs($other)->deleteJson('/api/v1/me/devices', ['token' => 'fcm-abc'])->assertNoContent();
        $this->assertSame(0, DeviceToken::count());
    }

    public function test_data_export_contains_orders_and_tickets(): void
    {
        $this->providerSends(Order::where('reference', $this->buyTickets($this->publishedEvent(), 1)->json('data.reference'))->with('latestPayment')->first(), 'succeeded');

        $this->actingAs($this->customer)->getJson('/api/v1/me/export')->assertOk()
            ->assertJsonPath('data.profile.email', $this->customer->email)
            ->assertJsonCount(1, 'data.orders')
            ->assertJsonCount(1, 'data.tickets');
    }

    public function test_account_deletion_anonymises_but_keeps_financial_records(): void
    {
        $order = Order::where('reference', $this->buyTickets($this->publishedEvent(), 2)->json('data.reference'))->with('latestPayment')->first();
        $this->providerSends($order, 'succeeded');
        $email = $this->customer->email;
        $token = $this->customer->createToken('phone')->plainTextToken;

        $this->withToken($token)->deleteJson('/api/v1/me', ['password' => 'Password123'])
            ->assertStatus(409)->assertJsonPath('error.code', 'has_active_purchases')->assertJsonPath('error.details.upcoming_tickets', 2);

        $this->withToken($token)->deleteJson('/api/v1/me', ['password' => 'Password123', 'forfeit_active_purchases' => true, 'reason' => 'Moving overseas'])
            ->assertNoContent();
        $this->resetAuth();

        $deleted = User::withTrashed()->find($this->customer->id);
        $this->assertSame('Deleted user', $deleted->name);
        $this->assertStringEndsWith('@deleted.invalid', $deleted->email);
        $this->assertNull($deleted->phone);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertSame(0, Ticket::where('status', Ticket::VALID)->count());
        $this->assertSame($deleted->id, $order->fresh()->user_id); // the order is kept for the books
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Password123', 'device_name' => 'x'])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.deleted']);

        // The email address is free to register again.
        $this->postJson('/api/v1/auth/register', ['name' => 'Back again', 'email' => $email, 'password' => 'Secure-Pass123',
            'password_confirmation' => 'Secure-Pass123', 'device_name' => 'x'])->assertCreated();
    }

    public function test_organizer_owners_must_close_their_organizer_first(): void
    {
        $organizer = $this->approvedOrganizer();

        $this->actingAs($organizer->owner)->deleteJson('/api/v1/me', ['password' => 'Password123'])
            ->assertStatus(409)->assertJsonPath('error.code', 'organizer_owner');
    }
}
