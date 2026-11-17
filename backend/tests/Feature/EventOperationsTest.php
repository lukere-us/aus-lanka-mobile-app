<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\EventOps\Models\TicketScan;
use App\Modules\Events\Models\TicketType;
use App\Modules\Identity\Models\Role;
use App\Modules\Orders\Models\Order;
use App\Modules\Organizers\Models\Organizer;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsEvents;
use Tests\Support\BuildsOrders;
use Tests\TestCase;

class EventOperationsTest extends TestCase
{
    use BuildsEvents, BuildsOrders, RefreshDatabase;

    private TicketType $type;

    private User $owner;

    private User $gate;

    private int $eventId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
        $this->type = $this->publishedEvent(stock: 50);
        $this->eventId = $this->type->event_id;
        $this->owner = $this->type->event->organizer->owner;
        $this->gate = $this->userWithRole(Role::GATE_STAFF, ['email' => 'gate.one@example.com']);
        $this->type->event->organizer->members()->attach($this->gate->id, ['role' => Organizer::ROLE_GATE_STAFF]);

        $ref = $this->buyTickets($this->type, 3)->json('data.reference');
        $this->providerSends(Order::where('reference', $ref)->with('latestPayment')->first(), 'succeeded');
    }

    private function org(string $method, string $path, array $data = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->json($method, "/api/v1/organizer/events/{$this->eventId}{$path}", $data);
    }

    private function scan(string $qr, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->gate)->postJson('/api/v1/scanner/check-in', ['qr' => $qr, 'event_id' => $this->eventId] + $extra);
    }

    public function test_gate_staff_must_be_assigned_to_the_event_and_to_their_gate(): void
    {
        $main = $this->org('POST', '/gates', ['name' => 'Main gate'])->assertCreated()->json('data.id');
        $vip = $this->org('POST', '/gates', ['name' => 'VIP'])->assertCreated()->json('data.id');
        [$t1, $t2] = Ticket::orderBy('id')->take(2)->get();
        $this->travel(29)->days(); // the scanner app lists events from 18 h ago to 7 days ahead

        $this->scan($t1->qr_token)->assertForbidden(); // not assigned yet

        $this->org('POST', '/staff', ['user_id' => $this->gate->id, 'gate_id' => $main])->assertCreated();
        $this->actingAs($this->gate)->getJson('/api/v1/scanner/events')->assertOk()
            ->assertJsonPath('data.0.id', $this->eventId)->assertJsonPath('data.0.my_gate_id', $main)->assertJsonCount(2, 'data.0.gates');

        $this->scan($t1->qr_token, ['gate_id' => $vip])->assertForbidden()->assertJsonPath('error.code', 'wrong_gate');
        $this->scan($t1->qr_token)->assertOk(); // counted at their own gate
        $this->assertSame($main, TicketScan::where('result', 'admitted')->value('gate_id'));

        $this->org('DELETE', "/staff/{$this->gate->id}")->assertNoContent();
        $this->scan($t2->qr_token)->assertForbidden();
    }

    public function test_only_team_members_can_be_assigned(): void
    {
        $outsider = $this->userWithRole(Role::CUSTOMER);

        $this->org('POST', '/staff', ['user_id' => $outsider->id])->assertUnprocessable()->assertJsonPath('error.code', 'not_staff');
    }

    public function test_owner_scans_any_gate_and_revoked_devices_are_refused(): void
    {
        $device = $this->actingAs($this->owner)->postJson('/api/v1/scanner/devices', ['device_uid' => 'ipad-7f3a', 'name' => 'Gate iPad 1'])
            ->assertCreated()->json('data.id');
        $ticket = Ticket::first();

        $this->scan($ticket->qr_token, ['device_id' => $device], $this->owner)->assertOk();
        $this->actingAs($this->owner)->postJson("/api/v1/organizer/devices/{$device}/revoke")->assertOk();

        $this->scan(Ticket::skip(1)->first()->qr_token, ['device_id' => $device], $this->owner)->assertForbidden()->assertJsonPath('error.code', 'device_revoked');
        $this->actingAs($this->owner)->postJson('/api/v1/scanner/devices', ['device_uid' => 'ipad-7f3a', 'name' => 'again'])->assertForbidden();
    }

    public function test_every_scan_attempt_is_logged_and_live_stats_add_up(): void
    {
        $this->org('POST', '/staff', ['user_id' => $this->gate->id])->assertCreated();
        $ticket = Ticket::first();

        $this->scan($ticket->qr_token)->assertOk();
        $this->scan($ticket->qr_token)->assertStatus(409);
        $this->scan('TK1.not-a-ticket-at-all-000000000000')->assertNotFound();

        $this->assertSame(['admitted', 'already_used', 'not_found'], TicketScan::orderBy('id')->pluck('result')->all());
        $this->assertSame(0, TicketScan::where('note', 'like', 'TK1.%')->count()); // QR values are never stored

        $this->org('GET', '/stats')->assertOk()
            ->assertJsonPath('data.tickets_issued', 3)
            ->assertJsonPath('data.checked_in', 1)
            ->assertJsonPath('data.still_to_arrive', 2)
            ->assertJsonPath('data.admitted_last_15_min', 1)
            ->assertJsonPath('data.rejected_scans.already_used', 1)
            ->assertJsonPath('data.by_ticket_type.0.checked_in', 1);
        $this->actingAs($this->gate)->getJson("/api/v1/scanner/events/{$this->eventId}/stats")->assertOk()->assertJsonPath('data.checked_in', 1);
        $this->org('GET', '/scans')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_manual_admit_and_undo_are_audited_and_single_use(): void
    {
        $ticket = Ticket::first();

        $this->org('POST', "/tickets/{$ticket->id}/admit", ['reason' => 'Phone battery dead, ID checked'])->assertOk()->assertJsonPath('data.status', 'used');
        $this->org('POST', "/tickets/{$ticket->id}/admit", ['reason' => 'again'])->assertStatus(409);
        $this->org('POST', "/tickets/{$ticket->id}/undo-check-in", ['reason' => 'Scanned wrong ticket of the group'])->assertOk()->assertJsonPath('data.status', 'valid');
        $this->org('POST', "/tickets/{$ticket->id}/undo-check-in", [])->assertUnprocessable(); // reason required

        $this->assertDatabaseHas('audit_logs', ['action' => 'ticket.manual_admit']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ticket.checkin_undone']);
        $this->assertSame(['manual_admit', 'undo'], TicketScan::orderBy('id')->pluck('result')->all());
    }

    public function test_attendee_list_masks_emails_searches_and_exports_csv(): void
    {
        $this->org('GET', '/attendees')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', $this->customer->name)
            ->assertJsonPath('data.0.email', fn ($e) => str_contains($e, '*') && ! str_contains($e, $this->customer->email));

        $ref = Order::value('reference');
        $this->org('GET', '/attendees?search='.$ref)->assertJsonCount(3, 'data');
        $this->org('GET', '/attendees?search=nobody-matches')->assertJsonCount(0, 'data');

        $csv = $this->org('GET', '/attendees.csv')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv); // BOM so Excel reads UTF-8 names correctly
        $csv = substr($csv, 3);
        $this->assertStringStartsWith('"Ticket ID","Ticket number",Name,Email', $csv);
        $this->assertSame(4, substr_count(trim($csv), "\n") + 1);
    }

    public function test_sales_summary_shows_stock_and_organizer_money(): void
    {
        $this->org('GET', '/sales')->assertOk()
            ->assertJsonPath('data.ticket_types.0.sold', 3)
            ->assertJsonPath('data.ticket_types.0.available', 47)
            ->assertJsonPath('data.gross_sales', '7500.00')
            ->assertJsonPath('data.platform_commission', '750.00')
            ->assertJsonPath('data.net_to_organizer', '6750.00')
            ->assertJsonPath('data.orders', 1);
    }

    public function test_other_organizers_and_gate_staff_cannot_use_the_organizer_tools(): void
    {
        $rival = $this->approvedOrganizer($this->userWithRole(Role::ORGANIZER))->owner;

        $this->org('GET', '/attendees', [], $rival)->assertNotFound();
        $this->org('GET', '/sales', [], $this->gate)->assertForbidden();
        $this->org('POST', '/gates', ['name' => 'x'], $this->gate)->assertForbidden();
    }
}
