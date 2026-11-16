<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Events\Models\TicketType;
use App\Modules\Identity\Models\Role;
use App\Modules\Movies\Models\RentalPackage;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Refunds\Models\Refund;
use App\Modules\Rentals\Models\Rental;
use App\Modules\Tickets\Models\Ticket;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsEvents;
use Tests\Support\BuildsOrders;
use Tests\TestCase;

class RefundAndPayoutTest extends TestCase
{
    use BuildsEvents, BuildsOrders, RefreshDatabase;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
        $this->finance = $this->userWithRole(Role::FINANCE_MANAGER);
    }

    private function settings(array $values): void
    {
        $this->actingAs($this->userWithRole(Role::SUPER_ADMIN))->putJson('/api/v1/admin/settings', $values)->assertOk();
    }

    /** Buy $qty AUD 2,500 tickets and pay. */
    private function paidTicketOrder(TicketType $type, int $qty = 2, ?User $buyer = null): Order
    {
        $ref = $this->buyTickets($type, $qty, $buyer)->assertCreated()->json('data.reference');
        $order = Order::where('reference', $ref)->with('latestPayment')->first();
        $this->providerSends($order, 'succeeded')->assertOk();

        return $order->fresh();
    }

    public function test_booking_fee_and_commission_follow_admin_settings(): void
    {
        $this->settings(['booking_fee_per_ticket' => '3.50', 'booking_fee_bps' => 100, 'ticket_commission_bps' => 800]);
        $type = $this->publishedEvent();

        // 2 x 2,500 = 5,000; fee = 2 x 3.50 + 1% of 5,000 = 57.00; total 5,057.00
        $this->buyTickets($type, 2)->assertCreated()
            ->assertJsonPath('data.fee_total.amount', '57.00')
            ->assertJsonPath('data.total.amount', '5057.00');
        $this->providerSends(Order::with('latestPayment')->first(), 'succeeded')->assertOk();

        $owner = $type->event->organizer->owner;
        // Organizer earns ticket revenue less 8% commission; the booking fee is the platform's.
        $this->actingAs($owner)->getJson('/api/v1/organizer/balance')->assertOk()
            ->assertJsonPath('data.total', '4600.00')
            ->assertJsonPath('data.available', '0.00')   // held until after the event
            ->assertJsonPath('data.pending', '4600.00')
            ->assertJsonPath('data.commission_bps', 800);
        $this->actingAs($owner)->getJson('/api/v1/organizer/ledger')->assertJsonCount(2, 'data');
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated']);
    }

    public function test_rentals_carry_no_booking_fee(): void
    {
        $this->settings(['booking_fee_per_ticket' => '3.50']);
        $this->seed(DemoContentSeeder::class);

        $this->checkout([['type' => 'rental_package', 'id' => RentalPackage::value('id'), 'quantity' => 1]])
            ->assertCreated()->assertJsonPath('data.fee_total.amount', '0.00')->assertJsonPath('data.total.amount', '4.99');
    }

    public function test_payouts_only_from_available_balance_after_the_hold(): void
    {
        $type = $this->publishedEvent();
        $this->paidTicketOrder($type, 2); // 5,000 - 10% = 4,500
        $organizer = $type->event->organizer;

        $this->actingAs($this->finance)->postJson("/api/v1/admin/organizers/{$organizer->id}/payouts", ['amount' => '100.00', 'reference' => 'TRF-1'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'payout_exceeds_available');

        $this->travel(33)->days(); // event + 2-day hold has passed
        $this->actingAs($this->finance)->postJson("/api/v1/admin/organizers/{$organizer->id}/payouts", ['amount' => '4000.00', 'reference' => 'TRF-1'])
            ->assertCreated();
        $this->actingAs($this->finance)->postJson("/api/v1/admin/organizers/{$organizer->id}/payouts", ['amount' => '600.00', 'reference' => 'TRF-2'])
            ->assertUnprocessable();

        $this->actingAs($this->finance)->getJson("/api/v1/admin/organizers/{$organizer->id}/balance")
            ->assertJsonPath('data.available', '500.00')->assertJsonPath('data.paid_out', '4000.00');
        $this->actingAs($this->customer)->postJson("/api/v1/admin/organizers/{$organizer->id}/payouts", ['amount' => '1', 'reference' => 'x'])->assertForbidden();
    }

    public function test_per_organizer_commission_override(): void
    {
        $type = $this->publishedEvent();
        $this->actingAs($this->finance)->putJson("/api/v1/admin/organizers/{$type->event->organizer_id}/commission", ['commission_bps' => 500])
            ->assertOk()->assertJsonPath('data.commission_bps', 500);

        $this->paidTicketOrder($type, 2);
        $this->actingAs($this->finance)->getJson("/api/v1/admin/organizers/{$type->event->organizer_id}/balance")->assertJsonPath('data.total', '4750.00');
    }

    public function test_customer_requests_ticket_refund_and_finance_approves(): void
    {
        $type = $this->publishedEvent(stock: 10);
        $order = $this->paidTicketOrder($type, 2);
        $ticket = Ticket::where('order_id', $order->id)->first();

        $refundId = $this->actingAs($this->customer)->postJson("/api/v1/orders/{$order->reference}/refunds", ['ticket_ids' => [$ticket->id], 'reason' => 'Cannot attend'])
            ->assertCreated()->assertJsonPath('data.status', 'requested')->assertJsonPath('data.amount.amount', '2500.00')->json('data.id');
        $this->assertSame(Ticket::VALID, $ticket->fresh()->status); // still valid until approved

        $this->actingAs($this->finance)->postJson("/api/v1/admin/refunds/{$refundId}/approve")->assertOk()->assertJsonPath('data.status', 'succeeded');

        $this->assertSame(Ticket::REFUNDED, $ticket->fresh()->status);
        $this->assertSame(OrderStatus::PartiallyRefunded, $order->fresh()->status);
        $this->assertSame([1, 9], [$type->fresh()->quantity_sold, $type->fresh()->available()]); // back on sale
        // Organizer: 4,500 earned - 2,500 refunded + 250 commission returned = 2,250
        $this->actingAs($this->finance)->getJson("/api/v1/admin/organizers/{$type->event->organizer_id}/balance")->assertJsonPath('data.total', '2250.00');

        // Refunded ticket cannot be scanned; it cannot be refunded twice.
        $this->actingAs($this->customer)->postJson("/api/v1/orders/{$order->reference}/refunds", ['ticket_ids' => [$ticket->id], 'reason' => 'again'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'not_refundable');
    }

    public function test_refunding_everything_marks_order_refunded(): void
    {
        $order = $this->paidTicketOrder($this->publishedEvent(), 2);

        $id = $this->actingAs($this->customer)->postJson("/api/v1/orders/{$order->reference}/refunds", ['reason' => 'Plans changed'])
            ->assertCreated()->assertJsonPath('data.amount.amount', '5000.00')->json('data.id');
        $this->actingAs($this->finance)->postJson("/api/v1/admin/refunds/{$id}/approve")->assertOk();

        $this->assertSame(OrderStatus::Refunded, $order->fresh()->status);
    }

    public function test_refund_policy_cutoff_and_staff_override(): void
    {
        $type = $this->publishedEvent();
        $order = $this->paidTicketOrder($type, 1);
        $this->travel(29)->days(); // event in ~24 h, inside the 48 h cutoff

        $this->actingAs($this->customer)->postJson("/api/v1/orders/{$order->reference}/refunds", ['reason' => 'late'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'nothing_to_refund');

        $this->actingAs($this->finance)->postJson("/api/v1/admin/orders/{$order->reference}/refunds", ['reason' => 'Goodwill', 'include_fees' => true])
            ->assertCreated()->assertJsonPath('data.status', 'succeeded');
    }

    public function test_used_ticket_cannot_be_refunded_and_rejection_needs_a_note(): void
    {
        $type = $this->publishedEvent();
        $order = $this->paidTicketOrder($type, 2);
        [$a, $b] = Ticket::where('order_id', $order->id)->get();

        $id = $this->actingAs($this->customer)->postJson("/api/v1/orders/{$order->reference}/refunds", ['ticket_ids' => [$a->id], 'reason' => 'x'])->json('data.id');
        $a->update(['status' => Ticket::USED]); // scanned at the gate before finance reviewed

        $this->actingAs($this->finance)->postJson("/api/v1/admin/refunds/{$id}/approve")->assertStatus(409)->assertJsonPath('error.code', 'ticket_not_refundable');
        $this->assertSame(Refund::REQUESTED, Refund::find($id)->status);
        $this->actingAs($this->finance)->postJson("/api/v1/admin/refunds/{$id}/reject", [])->assertUnprocessable();
        $this->actingAs($this->finance)->postJson("/api/v1/admin/refunds/{$id}/reject", ['note' => 'Ticket was used'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame(Ticket::VALID, $b->fresh()->status);
    }

    public function test_provider_refund_failure_restores_the_ticket(): void
    {
        $order = $this->paidTicketOrder($this->publishedEvent(), 1);
        $ticket = Ticket::where('order_id', $order->id)->first();
        $this->fakeGateway()->failRefunds();

        $this->actingAs($this->finance)->postJson("/api/v1/admin/orders/{$order->reference}/refunds", ['reason' => 'test'])
            ->assertCreated()->assertJsonPath('data.status', 'failed');

        $this->assertSame(Ticket::VALID, $ticket->fresh()->status);
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    public function test_unwatched_rental_refund_revokes_access(): void
    {
        $this->seed(DemoContentSeeder::class);
        $ref = $this->checkout([['type' => 'rental_package', 'id' => RentalPackage::value('id'), 'quantity' => 1]])->json('data.reference');
        $order = Order::where('reference', $ref)->with('latestPayment')->first();
        $this->providerSends($order, 'succeeded');
        $rental = Rental::firstOrFail();

        $id = $this->actingAs($this->customer)->postJson("/api/v1/orders/{$ref}/refunds", ['rental_ids' => [$rental->id], 'reason' => 'Wrong film'])
            ->assertCreated()->json('data.id');
        $this->actingAs($this->finance)->postJson("/api/v1/admin/refunds/{$id}/approve")->assertOk();

        $this->assertSame(Rental::REVOKED, $rental->fresh()->status);
        $this->actingAs($this->customer)->postJson("/api/v1/rentals/{$rental->id}/play")->assertForbidden();
    }

    public function test_started_rental_is_not_refundable(): void
    {
        $this->seed(DemoContentSeeder::class);
        $ref = $this->checkout([['type' => 'rental_package', 'id' => RentalPackage::value('id'), 'quantity' => 1]])->json('data.reference');
        $this->providerSends(Order::where('reference', $ref)->with('latestPayment')->first(), 'succeeded');
        $rental = Rental::firstOrFail();
        $this->actingAs($this->customer)->postJson("/api/v1/rentals/{$rental->id}/play")->assertOk();

        $this->actingAs($this->customer)->postJson("/api/v1/orders/{$ref}/refunds", ['rental_ids' => [$rental->id], 'reason' => 'x'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'not_refundable');
    }

    public function test_cancelling_an_event_refunds_every_ticket_including_fees(): void
    {
        $this->settings(['booking_fee_per_ticket' => '2.00']);
        $type = $this->publishedEvent();
        $first = $this->paidTicketOrder($type, 2);
        $second = $this->paidTicketOrder($type, 1, $this->userWithRole(Role::CUSTOMER));

        $this->actingAs($this->userWithRole(Role::EVENT_MANAGER))->postJson("/api/v1/admin/events/{$type->event_id}/cancel", ['reason' => 'Artist ill'])
            ->assertStatus(202);

        $this->assertSame(OrderStatus::Refunded, $first->fresh()->status);
        $this->assertSame(OrderStatus::Refunded, $second->fresh()->status);
        $this->assertSame(0, Ticket::where('status', Ticket::VALID)->count());
        $this->assertSame('5004.00', Refund::where('order_id', $first->id)->value('amount'));
        $this->getJson('/api/v1/events')->assertJsonCount(0, 'data');
        $this->actingAs($this->finance)->getJson("/api/v1/admin/organizers/{$type->event->organizer_id}/balance")->assertJsonPath('data.total', '0.00');
    }

    public function test_staff_can_void_a_ticket_without_refund(): void
    {
        $type = $this->publishedEvent(stock: 5);
        $order = $this->paidTicketOrder($type, 1);
        $ticket = Ticket::where('order_id', $order->id)->first();

        $this->actingAs($this->userWithRole(Role::ADMINISTRATOR))->postJson("/api/v1/admin/tickets/{$ticket->id}/cancel", ['reason' => 'Duplicate', 'release_stock' => true])
            ->assertOk();

        $this->assertSame(Ticket::CANCELLED, $ticket->fresh()->status);
        $this->assertSame(5, $type->fresh()->available());
    }
}
