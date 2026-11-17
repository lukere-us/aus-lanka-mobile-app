<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Models\Role;
use App\Modules\Movies\Models\RentalPackage;
use App\Modules\Orders\Models\Order;
use App\Modules\Tickets\Models\Ticket;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsEvents;
use Tests\Support\BuildsOrders;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use BuildsEvents, BuildsOrders, RefreshDatabase;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        // 10:00 Sydney time on 16 Nov 2026 (AEDT, UTC+11)
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Australia/Sydney'));
        $this->setUpOrders();
        $this->finance = $this->userWithRole(Role::FINANCE_MANAGER);
        $this->actingAs($this->userWithRole(Role::SUPER_ADMIN))->putJson('/api/v1/admin/settings', ['booking_fee_per_ticket' => '2.00'])->assertOk();
        $this->seed(DemoContentSeeder::class);
    }

    private function pay(string $reference): Order
    {
        $order = Order::where('reference', $reference)->with('latestPayment')->firstOrFail();
        $this->providerSends($order, 'succeeded')->assertOk();

        return $order;
    }

    private function sell(): void
    {
        $type = $this->publishedEvent();
        $this->pay($this->buyTickets($type, 2)->json('data.reference'));                                  // 5,000 + 4.00 fee
        $this->pay($this->checkout([['type' => 'rental_package', 'id' => RentalPackage::value('id'), 'quantity' => 1]])->json('data.reference')); // 4.99
        $refundable = $this->pay($this->buyTickets($type, 1, $this->userWithRole(Role::CUSTOMER))->json('data.reference')); // 2,500 + 2.00
        $this->actingAs($this->finance)->postJson("/api/v1/admin/orders/{$refundable->reference}/refunds", ['reason' => 'test'])->assertCreated();
    }

    public function test_dashboard_adds_up_revenue_refunds_commission_and_counts(): void
    {
        $this->sell();

        $data = $this->actingAs($this->finance)->getJson('/api/v1/admin/dashboard?from=2026-11-16&to=2026-11-16')->assertOk()->json('data');

        $this->assertSame(['from' => '2026-11-16', 'to' => '2026-11-16', 'timezone' => 'Australia/Sydney'], $data['period']);
        $this->assertSame([
            'gross' => '7510.99', 'tickets' => '7500.00', 'rentals' => '4.99', 'booking_fees' => '6.00',
            'refunds' => '2500.00', 'net' => '5010.99',
            'ticket_commission' => '500.00',               // 10% of 7,500 less 10% of the 2,500 refunded
            'platform_revenue_before_refunds' => '510.99', // commission + rentals + booking fees
        ], $data['revenue']);
        $this->assertSame(3, $data['counts']['paid_orders']);
        $this->assertSame(3, $data['counts']['tickets_sold']);
        $this->assertSame(1, $data['counts']['rentals_sold']);
        $this->assertSame('Live at Nelum Pokuna', $data['top_events'][0]['title']);
        $this->assertSame(3, $data['top_events'][0]['tickets']);
        $this->assertSame('Big Buck Bunny (demo)', $data['top_movies'][0]['title']);
        $this->assertSame([['date' => '2026-11-16', 'orders' => 3, 'gross' => '7510.99']], $data['daily']);
    }

    public function test_days_follow_sydney_time_not_utc(): void
    {
        // 23:30 Sydney on 16 Nov is 12:30 UTC on 16 Nov; 00:30 Sydney on 17 Nov is still 16 Nov in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-11-17 00:30', 'Australia/Sydney'));
        $this->pay($this->buyTickets($this->publishedEvent(), 1)->json('data.reference'));

        $this->actingAs($this->finance)->getJson('/api/v1/admin/dashboard?from=2026-11-16&to=2026-11-16')->assertJsonPath('data.counts.paid_orders', 0);
        $this->actingAs($this->finance)->getJson('/api/v1/admin/dashboard?from=2026-11-17&to=2026-11-17')->assertJsonPath('data.counts.paid_orders', 1);
    }

    public function test_needs_attention_lists_pending_work(): void
    {
        $this->sell();
        $order = Order::where('status', 'paid')->first();
        $ticket = Ticket::where('order_id', $order->id)->first();
        $this->actingAs($order->user)->postJson("/api/v1/orders/{$order->reference}/refunds", ['ticket_ids' => [$ticket->id], 'reason' => 'x'])->assertCreated();

        $this->actingAs($this->finance)->getJson('/api/v1/admin/dashboard')->assertOk()
            ->assertJsonPath('data.needs_attention.refund_requests', 1)
            ->assertJsonPath('data.needs_attention.organizers_pending', 0);
    }

    public function test_csv_exports_have_headers_and_rows(): void
    {
        $this->sell();
        $q = '?from=2026-11-16&to=2026-11-16';

        $orders = $this->actingAs($this->finance)->get("/api/v1/admin/reports/orders.csv{$q}")->assertOk()->streamedContent();
        $this->assertStringContainsString('Reference,"Created (UTC)","Paid (UTC)",Status', $orders);
        $this->assertSame(4, count(array_filter(explode("\n", trim($orders)))));

        $this->assertStringContainsString('"Event ID",Event,Organizer', $this->actingAs($this->finance)->get('/api/v1/admin/reports/ticket-sales.csv?from=2026-12-01&to=2026-12-31')->streamedContent());
        $this->assertStringContainsString('Live at Nelum Pokuna', $this->actingAs($this->finance)->get('/api/v1/admin/reports/ticket-sales.csv?from=2026-12-01&to=2026-12-31')->streamedContent());
        $this->assertStringContainsString(',succeeded,', $this->actingAs($this->finance)->get("/api/v1/admin/reports/refunds.csv{$q}")->streamedContent());
        $this->assertStringContainsString('Big Buck Bunny', $this->actingAs($this->finance)->get("/api/v1/admin/reports/rentals.csv{$q}")->streamedContent());
        $this->assertStringContainsString('Colombo Live', $this->actingAs($this->finance)->get('/api/v1/admin/reports/organizer-balances.csv')->streamedContent());
        $this->actingAs($this->finance)->get("/api/v1/admin/reports/payouts.csv{$q}")->assertOk();
    }

    public function test_csv_cells_cannot_inject_spreadsheet_formulas(): void
    {
        $this->customer->update(['name' => '=HYPERLINK("http://evil.example","click")']);
        $this->pay($this->buyTickets($type = $this->publishedEvent(), 1)->json('data.reference'));

        $csv = $this->actingAs($type->event->organizer->owner)->get("/api/v1/organizer/events/{$type->event_id}/attendees.csv")->streamedContent();
        $this->assertStringContainsString("\"'=HYPERLINK", $csv);
    }

    public function test_reports_need_permission_and_reject_long_periods(): void
    {
        $this->actingAs($this->customer)->getJson('/api/v1/admin/dashboard')->assertForbidden();
        $this->actingAs($this->userWithRole(Role::ORGANIZER))->get('/api/v1/admin/reports/orders.csv')->assertForbidden();
        $this->actingAs($this->finance)->getJson('/api/v1/admin/dashboard?from=2024-01-01&to=2026-01-01')->assertUnprocessable();
    }

    public function test_audit_log_filters_and_export(): void
    {
        $this->sell();
        $admin = $this->userWithRole(Role::ADMINISTRATOR);

        $this->actingAs($admin)->getJson('/api/v1/admin/audit-logs?action=refund.*')->assertOk()
            ->assertJsonPath('data.0.action', 'refund.succeeded')
            ->assertJsonPath('data.0.actor.email', $this->finance->email);
        $this->actingAs($admin)->getJson('/api/v1/admin/audit-logs?action=settings.updated')->assertJsonCount(1, 'data');

        $csv = $this->actingAs($admin)->get('/api/v1/admin/audit-logs?format=csv&action=order.paid')->assertOk()->streamedContent();
        $this->assertSame(4, count(array_filter(explode("\n", trim($csv)))));
        $this->actingAs($this->finance)->getJson('/api/v1/admin/audit-logs')->assertForbidden(); // audit.view is admin-only
    }
}
