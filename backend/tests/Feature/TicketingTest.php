<?php

namespace Tests\Feature;

use App\Modules\Events\Models\TicketReservation;
use App\Modules\Identity\Models\Role;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Services\TicketIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsEvents;
use Tests\Support\BuildsOrders;
use Tests\TestCase;

class TicketingTest extends TestCase
{
    use BuildsEvents, BuildsOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
    }

    private function order(string $reference): Order
    {
        return Order::where('reference', $reference)->with('latestPayment')->firstOrFail();
    }

    public function test_checkout_holds_stock_and_payment_issues_unique_tickets(): void
    {
        $type = $this->publishedEvent(stock: 10);

        $ref = $this->buyTickets($type, 3)->assertCreated()->assertJsonPath('data.total.amount', '7500.00')->json('data.reference');
        $type->refresh();
        $this->assertSame([10, 0, 3, 7], [$type->quantity_total, $type->quantity_sold, $type->quantity_reserved, $type->available()]);

        $this->providerSends($this->order($ref), 'succeeded')->assertOk();

        $type->refresh();
        $this->assertSame([3, 0], [$type->quantity_sold, $type->quantity_reserved]);
        $tickets = Ticket::where('user_id', $this->customer->id)->get();
        $this->assertCount(3, $tickets);
        $this->assertCount(3, $tickets->pluck('qr_token')->unique());
        foreach ($tickets as $t) {
            $this->assertMatchesRegularExpression('/^TK1\.[A-Za-z0-9_-]{32}$/', $t->qr_token);
            // Opaque: carries no personal or order data.
            $this->assertStringNotContainsString($this->customer->email, $t->qr_token);
            $this->assertStringNotContainsString($ref, $t->qr_token);
        }
        $this->assertSame(TicketReservation::CONVERTED, TicketReservation::first()->status);
    }

    public function test_cannot_oversell_and_failed_checkout_leaves_no_order(): void
    {
        $type = $this->publishedEvent(stock: 5);
        $this->buyTickets($type, 4)->assertCreated();

        $other = $this->userWithRole(Role::CUSTOMER);
        $this->buyTickets($type, 2, $other)->assertStatus(409)->assertJsonPath('error.code', 'sold_out')
            ->assertJsonPath('error.details.available', 1);

        $this->assertSame(1, Order::count());
        $this->assertSame(4, $type->fresh()->quantity_reserved);
        $this->buyTickets($type, 1, $other)->assertCreated();
        $this->buyTickets($type, 1, $this->userWithRole(Role::CUSTOMER))->assertStatus(409);
    }

    public function test_max_per_order_and_sale_window_are_enforced(): void
    {
        $type = $this->publishedEvent(typeAttributes: ['max_per_order' => 4, 'sale_starts_at' => now()->addDay()]);
        $this->buyTickets($type, 2)->assertStatus(409)->assertJsonPath('error.code', 'not_on_sale');

        $type->update(['sale_starts_at' => null]);
        $this->buyTickets($type, 5)->assertUnprocessable()->assertJsonPath('error.code', 'over_max_per_order');
    }

    public function test_draft_or_past_events_are_not_sellable(): void
    {
        $type = $this->publishedEvent();
        $type->event->update(['status' => 'draft']);
        $this->buyTickets($type, 1)->assertUnprocessable()->assertJsonPath('error.code', 'not_on_sale');
        $this->getJson('/api/v1/events')->assertJsonCount(0, 'data');
    }

    public function test_expiry_releases_held_stock_exactly_once(): void
    {
        $type = $this->publishedEvent(stock: 10);
        $this->buyTickets($type, 4)->assertCreated();

        $this->travel(config('payments.order_ttl_minutes') + 1)->minutes();
        $this->artisan('orders:expire-pending');
        $this->artisan('orders:expire-pending');

        $type->refresh();
        $this->assertSame([0, 0, 10], [$type->quantity_reserved, $type->quantity_sold, $type->available()]);
        $this->assertSame(TicketReservation::RELEASED, TicketReservation::first()->status);
    }

    public function test_issuing_twice_never_creates_extra_tickets(): void
    {
        $type = $this->publishedEvent(stock: 10);
        $order = $this->order($this->buyTickets($type, 2)->json('data.reference'));
        $this->providerSends($order, 'succeeded');

        $this->assertSame(0, app(TicketIssuer::class)->issueForOrder($order->id));
        $this->assertSame(2, Ticket::count());
        $this->assertSame(2, $type->fresh()->quantity_sold);
    }

    public function test_late_payment_after_expiry_gets_tickets_if_stock_remains(): void
    {
        $type = $this->publishedEvent(stock: 10);
        $order = $this->order($this->buyTickets($type, 2)->json('data.reference'));
        $this->travel(20)->minutes();
        $this->fakeGateway()->simulate($order->latestPayment->provider_reference, 'pending'); // expiry check sees no payment yet
        $this->artisan('orders:expire-pending');
        $this->assertSame(OrderStatus::Expired, $order->fresh()->status);

        $this->providerSends($order, 'succeeded')->assertOk();

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        $this->assertSame(2, Ticket::count());
        $this->assertSame([2, 0], [$type->fresh()->quantity_sold, $type->fresh()->quantity_reserved]);
    }

    public function test_late_payment_with_no_stock_left_is_flagged_not_oversold(): void
    {
        $type = $this->publishedEvent(stock: 2);
        $late = $this->order($this->buyTickets($type, 2)->json('data.reference'));
        $this->travel(20)->minutes();
        $this->artisan('orders:expire-pending');

        $other = $this->userWithRole(Role::CUSTOMER);
        $this->providerSends($this->order($this->buyTickets($type, 2, $other)->json('data.reference')), 'succeeded');
        $this->providerSends($late, 'succeeded');

        $this->assertSame(2, Ticket::count());
        $this->assertSame(0, Ticket::where('order_id', $late->id)->count());
        $this->assertSame(2, $type->fresh()->quantity_sold);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tickets.unfulfillable']);
    }

    public function test_wallet_shows_own_tickets_with_qr_only_to_holder(): void
    {
        $type = $this->publishedEvent();
        $this->providerSends($this->order($this->buyTickets($type, 2)->json('data.reference')), 'succeeded');
        $ticket = Ticket::first();

        $this->actingAs($this->customer)->getJson('/api/v1/tickets')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.event.title', 'Live at Nelum Pokuna')
            ->assertJsonPath('data.0.qr', $ticket->qr_token);

        $this->actingAs($this->userWithRole(Role::CUSTOMER))->getJson("/api/v1/tickets/{$ticket->id}")->assertNotFound();
    }
}
