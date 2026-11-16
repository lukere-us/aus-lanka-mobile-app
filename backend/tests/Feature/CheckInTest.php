<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Events\Models\TicketType;
use App\Modules\Identity\Models\Role;
use App\Modules\Orders\Models\Order;
use App\Modules\Organizers\Models\Organizer;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsEvents;
use Tests\Support\BuildsOrders;
use Tests\TestCase;

class CheckInTest extends TestCase
{
    use BuildsEvents, BuildsOrders, RefreshDatabase;

    private TicketType $type;

    private User $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
        $this->type = $this->publishedEvent();
        $this->gate = $this->userWithRole(Role::GATE_STAFF);
        $this->type->event->organizer->members()->attach($this->gate->id, ['role' => Organizer::ROLE_GATE_STAFF]);

        $ref = $this->buyTickets($this->type, 2)->json('data.reference');
        $order = Order::where('reference', $ref)->with('latestPayment')->first();
        $this->providerSends($order, 'succeeded');
    }

    private function scan(string $qr, ?int $eventId = null, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->gate)->postJson('/api/v1/scanner/check-in', ['qr' => $qr, 'event_id' => $eventId ?? $this->type->event_id]);
    }

    public function test_valid_ticket_is_admitted_once_and_rescans_are_rejected(): void
    {
        $ticket = Ticket::first();

        $this->scan($ticket->qr_token)->assertOk()->assertJsonPath('data.result', 'admitted')->assertJsonPath('data.ticket_type', 'General');
        $this->scan($ticket->qr_token)->assertStatus(409)->assertJsonPath('error.code', 'ticket_already_used')
            ->assertJsonStructure(['error' => ['details' => ['checked_in_at']]]);

        $this->assertSame(Ticket::USED, $ticket->fresh()->status);
        $this->assertSame($this->gate->id, $ticket->fresh()->checked_in_by);
        $this->assertSame(1, Ticket::where('status', Ticket::USED)->count());
    }

    public function test_unknown_wrong_event_and_cancelled_tickets_are_rejected(): void
    {
        $this->scan('TK1.not-a-real-ticket-000000000000000')->assertNotFound()->assertJsonPath('error.code', 'ticket_not_found');

        $otherEvent = $this->publishedEvent(organizer: $this->type->event->organizer);
        $this->scan(Ticket::first()->qr_token, $otherEvent->event_id)->assertStatus(409)->assertJsonPath('error.code', 'wrong_event');

        $cancelled = Ticket::skip(1)->first();
        $cancelled->update(['status' => Ticket::CANCELLED]);
        $this->scan($cancelled->qr_token)->assertStatus(409)->assertJsonPath('error.code', 'ticket_not_valid');
    }

    public function test_staff_of_another_organizer_and_customers_cannot_scan(): void
    {
        $foreignGate = $this->userWithRole(Role::GATE_STAFF);
        $this->approvedOrganizer($this->userWithRole(Role::ORGANIZER))->members()->attach($foreignGate->id, ['role' => 'gate_staff']);

        $this->scan(Ticket::first()->qr_token, as: $foreignGate)->assertForbidden();
        $this->scan(Ticket::first()->qr_token, as: $this->customer)->assertForbidden();
        $this->assertSame(0, Ticket::where('status', Ticket::USED)->count());
    }
}
