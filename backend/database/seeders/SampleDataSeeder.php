<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Currencies\Services\CurrencyService;
use App\Modules\EventOps\Models\EventGate;
use App\Modules\EventOps\Services\EventOpsService;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\TicketType;
use App\Modules\Events\Models\Venue;
use App\Modules\Events\Services\EventService;
use App\Modules\Finance\Services\LedgerService;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Services\AuthService;
use App\Modules\Movies\Models\Movie;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\CheckoutService;
use App\Modules\Organizers\Models\Organizer;
use App\Modules\Organizers\Services\OrganizerService;
use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Payments\Services\PaymentGatewayManager;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Refunds\Jobs\RefundCancelledEvent;
use App\Modules\Refunds\Services\RefundService;
use App\Modules\Rentals\Models\Rental;
use App\Modules\Rentals\Services\PlaybackService;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Services\CheckInService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Realistic sample data for local testing of the website and apps: customers, organizers, venues, events
 * (past, upcoming, waiting for approval, draft, cancelled), movies, paid / failed / pending orders in AUD,
 * LKR and USD, tickets and check-ins, rentals being watched, refunds and a payout.
 *
 * Everything goes through the real services (checkout, fake payment gateway webhooks, ledger, refunds),
 * with the clock moved back so the history spreads over the last five weeks.
 *
 *   php artisan db:seed --class=SampleDataSeeder
 *
 * Runs once (skips if the sample customers exist). Never runs in production or without the fake gateway.
 * Every sample account uses the password in self::PASSWORD.
 */
class SampleDataSeeder extends Seeder
{
    public const PASSWORD = 'Password!2345';

    private const MARKER_EMAIL = 'nimal.perera@example.com';

    private User $admin;

    private CarbonImmutable $now;

    /** @var array<string, User> */
    private array $customers = [];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Sample data is for local development only.');

            return;
        }
        if (config('payments.default') !== 'fake') {
            $this->command?->error('Set PAYMENT_GATEWAY=fake in .env first: sample orders are paid through the fake gateway.');

            return;
        }
        if (User::where('email', self::MARKER_EMAIL)->exists()) {
            $this->command?->warn('Sample data is already loaded (found '.self::MARKER_EMAIL.'). Nothing to do.');

            return;
        }

        // Do queued work (payments, tickets, notifications, mass refunds) right away instead of waiting for a worker.
        config(['queue.default' => 'sync']);
        $this->now = CarbonImmutable::now();
        $this->admin = User::whereHas('roles', fn ($q) => $q->where('slug', Role::SUPER_ADMIN))->firstOrFail();
        $this->call(DemoContentSeeder::class);

        try {
            $this->seed();
        } finally {
            $this->travel(null);
        }
        // What the scheduler would have done by now: expire rentals whose viewing window has passed.
        Artisan::call('rentals:expire');

        $this->command?->info('Sample data loaded. Every sample account uses the password '.self::PASSWORD);
        $this->command?->table(['Account', 'Email'], [
            ['Customer', self::MARKER_EMAIL],
            ['Customer', 'sarah.jones@example.com'],
            ['Organizer (Kasun Events)', 'kasun@example.com'],
            ['Organizer (Colombo Beats Australia)', 'dilani@example.com'],
            ['Organizer applying (pending)', 'ruwan@example.com'],
            ['Gate staff (Kasun Events)', 'gate.staff@example.com'],
            ['Finance staff', 'finance@example.com'],
            ['Content staff', 'content@example.com'],
            ['Event staff', 'events@example.com'],
        ]);
    }

    private function seed(): void
    {
        // --- Currencies: customers can pay in LKR and USD -----------------------------------------
        $this->travel(-40);
        $currencies = app(CurrencyService::class);
        $currencies->upsert('LKR', ['rate_per_base' => '196.50000000', 'is_active' => true], $this->admin);
        $currencies->upsert('USD', ['rate_per_base' => '0.65000000', 'is_active' => true], $this->admin);

        // --- Staff accounts ------------------------------------------------------------------------
        $this->staff('Fiona Finance', 'finance@example.com', Role::FINANCE_MANAGER);
        $this->staff('Chamari Content', 'content@example.com', Role::CONTENT_MANAGER);
        $this->staff('Ethan Events', 'events@example.com', Role::EVENT_MANAGER);

        // --- Movies -----------------------------------------------------------------------------------
        $movies = $this->movies();

        // --- Customers, joining over five weeks --------------------------------------------------------
        $people = [
            ['Nimal Perera', self::MARKER_EMAIL, '+61412000101', 'AUD'],
            ['Sarah Jones', 'sarah.jones@example.com', '+61412000102', 'AUD'],
            ['Kamal Fernando', 'kamal.fernando@example.com', '+94771000103', 'LKR'],
            ['Priya Wickramasinghe', 'priya.w@example.com', '+61412000104', 'AUD'],
            ['James Chen', 'james.chen@example.com', '+61412000105', 'AUD'],
            ['Anushka Silva', 'anushka.silva@example.com', '+94771000106', 'LKR'],
            ['Tom Walker', 'tom.walker@example.com', null, 'AUD'],
            ['Sanduni Jayawardena', 'sanduni.j@example.com', '+61412000108', 'AUD'],
            ['Michael Brown', 'michael.brown@example.com', '+14155550109', 'USD'],
            ['Tharindu Bandara', 'tharindu.b@example.com', '+61412000110', 'AUD'],
            ['Emily Davis', 'emily.davis@example.com', null, 'AUD'],
            ['Ishara Gunawardena', 'ishara.g@example.com', '+94771000112', 'LKR'],
        ];
        foreach ($people as $i => [$name, $email, $phone, $currency]) {
            $this->travel(-36 + $i * 3, 10 + $i % 8);
            $this->customers[$email] = $this->customer($name, $email, $phone, $currency);
        }
        $c = array_values($this->customers);

        // --- Organizers ------------------------------------------------------------------------------
        $this->travel(-35);
        [$kasun, $kasunOwner] = $this->organizer('Kasun Events Pty Ltd', 'Kasun Rajapaksha', 'kasun@example.com', '+61400111222', '51 824 753 556');
        $this->travel(-31);
        [$beats, $beatsOwner] = $this->organizer('Colombo Beats Australia', 'Dilani Herath', 'dilani@example.com', '+61400333444', null);

        $melbourne = Venue::create(['organizer_id' => $kasun->id, 'name' => 'Melbourne Recital Centre', 'address' => '31 Sturt St, Southbank', 'city' => 'Melbourne', 'country' => 'AU', 'timezone' => 'Australia/Melbourne', 'capacity' => 1000]);
        $sydney = Venue::create(['organizer_id' => $kasun->id, 'name' => 'Parramatta Riverside Theatres', 'address' => '353 Church St, Parramatta', 'city' => 'Sydney', 'country' => 'AU', 'timezone' => 'Australia/Sydney', 'capacity' => 760]);
        $brisbane = Venue::create(['organizer_id' => $beats->id, 'name' => 'Brisbane Powerhouse', 'address' => '119 Lamington St, New Farm', 'city' => 'Brisbane', 'country' => 'AU', 'timezone' => 'Australia/Brisbane', 'capacity' => 600]);
        $perth = Venue::create(['organizer_id' => $beats->id, 'name' => 'Perth Convention Centre', 'address' => '21 Mounts Bay Rd', 'city' => 'Perth', 'country' => 'AU', 'timezone' => 'Australia/Perth', 'capacity' => 2500]);

        // Gate staff for Kasun Events
        $this->travel(-30);
        $gateUser = app(AuthService::class)->registerCustomer(['name' => 'Ravi Gate', 'email' => 'gate.staff@example.com', 'password' => self::PASSWORD]);
        $gateUser->forceFill(['email_verified_at' => now()])->save();
        app(OrganizerService::class)->addStaff($kasun, $gateUser->email, Organizer::ROLE_GATE_STAFF);

        // --- Event 1: already happened (6 days ago), sold well, most people checked in, paid out ---------
        $this->travel(-33);
        $past = $this->event($kasun, $melbourne, 'Sinhala Hits Live – Melbourne', 'concert', $this->localAt($melbourne, -6, '19:30'),
            'Two hours of classic and new Sinhala songs with a live band.', [
                ['General admission', 'general', '45.00', 400],
                ['VIP (front rows + meet & greet)', 'vip', '120.00', 40],
            ]);
        $mainGate = EventGate::create(['event_id' => $past->id, 'name' => 'Main entrance']);
        EventGate::create(['event_id' => $past->id, 'name' => 'VIP entrance']);
        app(EventOpsService::class)->assignStaff($past, $gateUser->id, $mainGate->id, $kasunOwner);
        $pastTypes = $past->ticketTypes()->orderBy('id')->get();
        $pastSales = [
            [-31, 0, 0, 2], [-29, 1, 1, 2], [-27, 2, 0, 4], [-25, 3, 0, 2], [-24, 4, 1, 1], [-22, 5, 0, 3],
            [-20, 6, 0, 2], [-18, 7, 0, 5], [-16, 8, 1, 2], [-14, 9, 0, 3], [-12, 10, 0, 2], [-10, 11, 0, 6],
            [-9, 0, 1, 2], [-8, 3, 0, 4], [-7, 5, 0, 2],
        ];
        foreach ($pastSales as $n => [$day, $who, $type, $qty]) {
            $this->travel($day, 9 + $n % 11);
            $this->buy($c[$who], [['ticket_type', $pastTypes[$type]->id, $qty]], $c[$who]->preferred_currency);
        }
        // Event night: scan most tickets at the door
        $this->at($this->localAt($melbourne, -6, '18:45'));
        $checkIn = app(CheckInService::class);
        foreach (Ticket::where('event_id', $past->id)->where('status', Ticket::VALID)->orderBy('id')->get() as $i => $ticket) {
            if ($i % 7 === 6) {
                continue; // a few no-shows
            }
            $this->at($this->localAt($melbourne, -6, '18:45')->addMinutes($i * 2));
            $checkIn->checkIn($i % 3 ? $gateUser : $kasunOwner, $ticket->qr_token, $past->id, $i % 3 ? $mainGate->id : null);
        }
        $past->update(['status' => EventStatus::Completed]);

        // --- Event 2: upcoming in Sydney, selling now; early bird already closed ---------------------------
        $this->travel(-21);
        $sydneyNight = $this->event($kasun, $sydney, 'Golden Oldies Sinhala Night – Sydney', 'concert', $this->localAt($sydney, 16, '19:00'),
            'An evening of much-loved songs from the 70s and 80s. Dinner available at the venue.', [
                ['Early bird', 'early_bird', '35.00', 100, ['sale_ends_at' => $this->now->subDays(5)]],
                ['General admission', 'general', '49.00', 500],
                ['VIP table (seats 1)', 'vip', '110.00', 60],
            ]);
        $sydneyTypes = $sydneyNight->ticketTypes()->orderBy('id')->get();
        foreach ([[-20, 0, 0, 2], [-18, 2, 0, 3], [-15, 4, 0, 2], [-12, 6, 0, 4], [-8, 1, 1, 2], [-6, 3, 2, 2], [-4, 7, 1, 3],
            [-3, 9, 1, 2], [-2, 10, 2, 1], [-1, 11, 1, 4], [0, 5, 1, 2]] as $n => [$day, $who, $type, $qty]) {
            $this->travel($day, 8 + $n % 12);
            $this->buy($c[$who], [['ticket_type', $sydneyTypes[$type]->id, $qty]], $c[$who]->preferred_currency);
        }

        // --- Event 3: Brisbane, just went on sale ------------------------------------------------------
        $this->travel(-9);
        $baila = $this->event($beats, $brisbane, 'Baila Fiesta Brisbane', 'festival', $this->localAt($brisbane, 30, '17:00'),
            'Baila, food stalls and dancing until late. Family friendly until 9 pm.', [
                ['General admission', 'general', '30.00', 550],
                ['Family pass (2 adults + 2 kids)', 'custom', '85.00', 40, ['description' => 'Children under 12 must be with an adult.']],
            ]);
        $bailaTypes = $baila->ticketTypes()->orderBy('id')->get();
        foreach ([[-8, 1, 0, 2], [-5, 4, 1, 1], [-3, 8, 0, 4], [-1, 9, 1, 1], [0, 3, 0, 2]] as $n => [$day, $who, $type, $qty]) {
            $this->travel($day, 11 + $n);
            $this->buy($c[$who], [['ticket_type', $bailaTypes[$type]->id, $qty]], $c[$who]->preferred_currency);
        }

        // --- Event 4: Perth festival waiting for platform approval --------------------------------------
        $this->travel(-1);
        $this->event($beats, $perth, 'Sri Lankan Food & Music Festival – Perth', 'festival', $this->localAt($perth, 45, '11:00'),
            'Street food, cooking demos and live music all day.', [
                ['Day pass', 'general', '25.00', 2000],
                ['Day pass + tasting plate', 'custom', '45.00', 300],
            ], publish: false, submit: true);

        // --- Event 5: draft, still being set up ----------------------------------------------------------
        $this->event($kasun, $melbourne, 'New Year Countdown Party – Melbourne', 'concert', $this->localAt($melbourne, 44, '21:00'),
            null, [['General admission', 'general', '60.00', 800]], publish: false, submit: false);

        // --- Event 6: comedy night that was cancelled (everyone refunded in full) -------------------------
        $this->travel(-14);
        $comedy = $this->event($beats, $brisbane, 'Lankan Laughs Comedy Night', 'comedy', $this->localAt($brisbane, 10, '20:00'),
            'Stand-up in Sinhala and English.', [['Standard', 'general', '28.00', 300]]);
        $comedyType = $comedy->ticketTypes()->first();
        foreach ([[-13, 2, 2], [-11, 6, 3], [-7, 10, 2]] as [$day, $who, $qty]) {
            $this->travel($day, 13);
            $this->buy($c[$who], [['ticket_type', $comedyType->id, $qty]], $c[$who]->preferred_currency);
        }
        $this->travel(-3, 10);
        $this->cancelEvent($comedy, 'The headline act is unwell. Everyone gets a full refund.');

        // --- Movie rentals over the month; most get watched ------------------------------------------------
        $packages = collect($movies)->map(fn (Movie $m) => $m->packages()->where('status', 'active')->orderBy('price')->first())->filter()->values();
        $rentals = [
            [-34, 0, 0], [-32, 1, 1], [-30, 2, 2], [-28, 3, 0], [-26, 4, 3], [-23, 5, 1], [-21, 6, 4], [-19, 7, 2],
            [-17, 8, 0], [-15, 9, 5], [-13, 10, 1], [-11, 11, 3], [-9, 0, 2], [-8, 2, 4], [-6, 4, 5], [-5, 6, 1],
            [-4, 8, 2], [-3, 1, 3], [-2, 3, 5], [-1, 5, 0], [0, 7, 4],
        ];
        $playback = app(PlaybackService::class);
        foreach ($rentals as $n => [$day, $who, $p]) {
            $this->travel($day, 18 + $n % 5);
            $order = $this->buy($c[$who], [['rental_package', $packages[$p % $packages->count()]->id, 1]], $c[$who]->preferred_currency);
            if ($n % 4 !== 3) { // most rentals get started an hour later
                $this->travel($day, 19 + $n % 5);
                $rental = Rental::where('order_id', $order->id)->first();
                $playback->authorize($c[$who], $rental->id);
                $playback->saveProgress($c[$who], $rental->id, 1200 + $n * 60, $n % 3 === 0);
            }
        }

        // --- Payments that didn't go through -------------------------------------------------------------
        $this->travel(-10, 21);
        $this->buy($c[6], [['rental_package', $packages[0]->id, 1]], 'AUD', outcome: 'failed');
        $this->travel(-2, 22);
        $this->buy($c[8], [['ticket_type', $sydneyTypes[1]->id, 2]], 'USD', outcome: 'failed');
        $this->travel(0);
        $this->buy($c[11], [['ticket_type', $bailaTypes[0]->id, 2]], 'LKR', outcome: null); // still on the payment page

        // --- Refunds -----------------------------------------------------------------------------------------
        $refunds = app(RefundService::class);
        // Two customers ask for a ticket refund on the Sydney night (waiting for a decision)
        $this->travel(-2, 9);
        $this->requestTicketRefund($c[3], $sydneyNight, 1, 'My sister can no longer come.');
        $this->travel(-1, 15);
        $this->requestTicketRefund($c[9], $sydneyNight, 2, 'Double-booked that night, sorry!');
        // One request was rejected
        $this->travel(-4, 10);
        $rejected = $this->requestTicketRefund($c[7], $sydneyNight, 1, 'Changed my mind.');
        $this->travel(-3, 11);
        $refunds->reject($rejected, $this->admin, 'Tickets are only refundable if you can’t attend for a reason outside your control.');
        // A rental refund approved by finance (playback problem)
        $this->travel(-1, 20);
        $order = $this->buy($c[2], [['rental_package', $packages[1]->id, 1]], 'LKR');
        $this->travel(0, 8);
        $refunds->refundByStaff($this->admin, $order, [], [], 'Video kept buffering on the customer’s TV.', false);

        // --- Payout to Kasun Events for the Melbourne show ----------------------------------------------------
        $this->travel(-1, 14);
        $available = app(LedgerService::class)->balance($kasun)['available'];
        if ((float) $available > 100) {
            app(LedgerService::class)->recordPayout($kasun, number_format(floor((float) $available * 0.8), 2, '.', ''), 'CBA-TRF-'.strtoupper(Str::random(6)), 'First payout for Sinhala Hits Live – Melbourne', $this->admin);
        }

        // --- Organizer still applying --------------------------------------------------------------------------
        $this->travel(-1, 16);
        $ruwan = app(AuthService::class)->registerCustomer(['name' => 'Ruwan Senanayake', 'email' => 'ruwan@example.com', 'phone' => '+61400555666', 'password' => self::PASSWORD]);
        $ruwan->forceFill(['email_verified_at' => now()])->save();
        app(OrganizerService::class)->apply($ruwan, ['name' => 'Lanka Comedy Club', 'contact_email' => 'ruwan@example.com', 'contact_phone' => '+61400555666']);
    }

    // ------------------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------------------

    /** Move the clock to $days from today at $hour:00 (Sydney time). Null goes back to the real time. */
    private function travel(?int $days, int $hour = 12): void
    {
        if ($days === null) {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();

            return;
        }
        $this->at($this->now->setTimezone('Australia/Sydney')->addDays($days)->setTime($hour, 0)->utc());
    }

    private function at(CarbonImmutable $moment): void
    {
        // Never move past the real "now": that would create records dated in the future.
        $moment = $moment->greaterThan($this->now) ? $this->now : $moment;
        Carbon::setTestNow(Carbon::instance($moment));
        CarbonImmutable::setTestNow($moment);
    }

    /** A local time at the venue, $days from today. */
    private function localAt(Venue $venue, int $days, string $time): CarbonImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $this->now->setTimezone($venue->timezone)->addDays($days)->setTime($h, $m)->utc();
    }

    private function staff(string $name, string $email, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => self::PASSWORD, 'status' => User::STATUS_ACTIVE]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->roles()->attach(Role::whereIn('slug', [$role])->pluck('id'));

        return $user;
    }

    private function customer(string $name, string $email, ?string $phone, string $currency): User
    {
        $user = app(AuthService::class)->registerCustomer(['name' => $name, 'email' => $email, 'phone' => $phone, 'password' => self::PASSWORD]);
        $user->forceFill([
            'email_verified_at' => now(),
            'phone_verified_at' => $phone ? now() : null,
            'preferred_currency' => $currency,
            'marketing_opt_in' => strlen($name) % 2 === 0,
            'last_login_at' => now(),
        ])->save();

        return $user;
    }

    /** @return array{0: Organizer, 1: User} */
    private function organizer(string $company, string $ownerName, string $email, string $phone, ?string $abn): array
    {
        $owner = $this->customer($ownerName, $email, $phone, 'AUD');
        $organizer = app(OrganizerService::class)->apply($owner, [
            'name' => $company, 'contact_email' => $email, 'contact_phone' => $phone, 'business_registration_no' => $abn,
        ]);
        $this->at(CarbonImmutable::now()->addHours(5));
        app(OrganizerService::class)->decide($organizer, Organizer::APPROVED, null, $this->admin);

        return [$organizer->fresh(), $owner->fresh()];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4?: array<string, mixed>}>  $types
     */
    private function event(Organizer $organizer, Venue $venue, string $title, string $category, CarbonImmutable $startsAt, ?string $description, array $types, bool $publish = true, bool $submit = true): Event
    {
        $events = app(EventService::class);
        $event = $events->create($organizer, [
            'title' => $title, 'category' => $category, 'venue_id' => $venue->id, 'starts_at' => $startsAt,
            'doors_open_at' => $startsAt->subMinutes(45), 'ends_at' => $startsAt->addHours(3), 'description' => $description,
            'terms' => 'No refunds within 48 hours of the event, unless it is cancelled. Bring your QR code on your phone.',
        ]);
        foreach ($types as $i => [$name, $kind, $price, $qty]) {
            $events->addTicketType($event, ['name' => $name, 'kind' => $kind, 'price' => $price, 'quantity_total' => $qty, 'sort_order' => $i] + ($types[$i][4] ?? []));
        }
        if ($submit) {
            $events->submit($event->fresh());
        }
        if ($publish) {
            $this->at(CarbonImmutable::now()->addHours(3));
            $events->review($event->fresh(), true, null, $this->admin);
        }

        return $event->fresh();
    }

    /**
     * Checkout and pay through the fake gateway's signed webhook, like the app does.
     *
     * @param  list<array{0: string, 1: int, 2: int}>  $items
     */
    private function buy(User $buyer, array $items, ?string $currency, ?string $outcome = 'succeeded'): Order
    {
        [$order] = app(CheckoutService::class)->checkout(
            $buyer,
            array_map(fn ($i) => ['type' => $i[0], 'id' => $i[1], 'quantity' => $i[2]], $items),
            'sample-'.Str::random(20),
            $currency,
        );
        $order->load('latestPayment');
        if ($outcome !== null) {
            /** @var FakeGateway $gateway */
            $gateway = app(PaymentGatewayManager::class)->driver('fake');
            $hook = $gateway->simulate($order->latestPayment->provider_reference, $outcome);
            app(PaymentService::class)->receiveWebhook('fake', $hook['body'], $hook['headers']);
        }

        $order = $order->fresh();
        if ($outcome === 'succeeded' && $order->paid_at === null) {
            throw new RuntimeException("Sample order {$order->reference} did not get paid ({$order->status->value}).");
        }

        return $order;
    }

    private function requestTicketRefund(User $customer, Event $event, int $count, string $reason)
    {
        $ticket = Ticket::where('event_id', $event->id)->where('user_id', $customer->id)->where('status', Ticket::VALID)->orderBy('id')->firstOrFail();
        $ids = Ticket::where('order_id', $ticket->order_id)->where('status', Ticket::VALID)->orderBy('id')->limit($count)->pluck('id')->all();

        return app(RefundService::class)->request($customer, Order::findOrFail($ticket->order_id), $ids, [], $reason);
    }

    /** Same steps as the admin "cancel event" action. */
    private function cancelEvent(Event $event, string $reason): void
    {
        $event->update(['status' => EventStatus::Cancelled, 'review_note' => $reason]);
        TicketType::where('event_id', $event->id)->update(['status' => 'hidden']);
        RefundCancelledEvent::dispatchSync($event->id, $this->admin->id, $reason);
    }

    /** @return list<Movie> */
    private function movies(): array
    {
        $list = [
            ['Monsoon Letters', 'A young teacher posted to a hill-country school finds a box of unsent letters from 1971.', 2023, 118, 'PG', ['Drama', 'Romance'], ['Sinhala'], [['48-hour rental', 'HD', '5.99'], ['48-hour rental', '4K', '7.99']]],
            ['The Last Tea Planter', 'A family saga across three generations on a fading tea estate.', 2022, 134, 'M', ['Drama'], ['Sinhala', 'English'], [['72-hour rental', 'HD', '6.99']]],
            ['Galle Face Nights', 'Four friends, one night, and a city that never sleeps.', 2024, 102, 'M', ['Comedy'], ['Sinhala'], [['48-hour rental', 'HD', '4.99']]],
            ['Kandy Express', 'A heist on the overnight train to Kandy goes hilariously wrong.', 2021, 96, 'PG', ['Action', 'Comedy'], ['Sinhala', 'Tamil'], [['48-hour rental', 'HD', '4.49']]],
            ['Ocean Between Us', 'A Melbourne nurse returns to Jaffna to find the brother she lost.', 2024, 121, 'MA15+', ['Drama'], ['Tamil', 'English'], [['48-hour rental', 'HD', '5.49']]],
            ['Little Elephant', 'An orphaned elephant and the boy who refused to give up on him.', 2020, 88, 'G', ['Family', 'Animation'], ['English', 'Sinhala'], [['7-day family rental', 'HD', '8.99']]],
        ];
        $movies = [];
        foreach ($list as $i => [$title, $synopsis, $year, $runtime, $rating, $genres, $langs, $packages]) {
            $movie = Movie::create([
                'title' => $title, 'slug' => Str::slug($title).'-sample', 'synopsis' => $synopsis, 'release_year' => $year,
                'runtime_minutes' => $runtime, 'classification' => $rating, 'genres' => $genres, 'languages' => $langs,
                'status' => Movie::PUBLISHED, 'published_at' => now()->subDays(5 - $i),
            ]);
            $movie->assets()->create(['kind' => 'feature', 'provider' => 'sample', 'status' => 'ready', 'duration_seconds' => $runtime * 60, 'provider_asset_id' => 'sample-'.$movie->slug]);
            foreach ($packages as [$name, $quality, $price]) {
                $movie->packages()->create([
                    'name' => $name, 'quality' => $quality, 'price' => $price, 'currency' => config('marketplace.currency'),
                    'watch_hours' => str_contains($name, '72') ? 72 : (str_contains($name, '7-day') ? 168 : 48), 'start_within_days' => 30, 'status' => 'active',
                ]);
            }
            $movies[] = $movie;
        }
        // One movie still being prepared by the content team
        Movie::create(['title' => 'Sigiriya: The Sky Fortress', 'slug' => 'sigiriya-the-sky-fortress-sample', 'synopsis' => 'Documentary. Coming soon.',
            'release_year' => 2025, 'runtime_minutes' => 74, 'classification' => 'G', 'genres' => ['Documentary'], 'languages' => ['English'], 'status' => Movie::DRAFT]);

        return $movies;
    }
}
