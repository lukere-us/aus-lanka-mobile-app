<?php

namespace App\Modules\EventOps\Services;

use App\Http\Responses\ApiException;
use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\EventOps\Models\EventGate;
use App\Modules\EventOps\Models\EventStaff;
use App\Modules\EventOps\Models\TicketScan;
use App\Modules\Events\Models\Event;
use App\Modules\Finance\Models\LedgerEntry;
use App\Modules\Organizers\Models\Organizer;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** Organizer-side event operations: gates, staff, live stats, sales, manual gate exceptions (F09, G01, G04). */
class EventOpsService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function assignStaff(Event $event, int $userId, ?int $gateId, User $by): EventStaff
    {
        $isStaff = Organizer::whereKey($event->organizer_id)->whereHas('members', fn ($q) => $q->where('users.id', $userId)
            ->whereIn('organizer_members.role', [Organizer::ROLE_GATE_STAFF, Organizer::ROLE_MANAGER]))->exists();
        if (! $isStaff) {
            throw new ApiException(422, 'not_staff', 'Add this person as gate staff in your organizer team first.');
        }
        if ($gateId !== null && ! EventGate::where('event_id', $event->id)->whereKey($gateId)->exists()) {
            throw new ApiException(422, 'unknown_gate', 'That gate does not belong to this event.');
        }

        $row = EventStaff::updateOrCreate(['event_id' => $event->id, 'user_id' => $userId], ['gate_id' => $gateId, 'assigned_by' => $by->id]);
        $this->audit->record('event.staff_assigned', $event, ['user_id' => $userId, 'gate_id' => $gateId]);

        return $row;
    }

    public function unassignStaff(Event $event, int $userId): void
    {
        EventStaff::where('event_id', $event->id)->where('user_id', $userId)->delete();
        $this->audit->record('event.staff_unassigned', $event, ['user_id' => $userId]);
    }

    /** Live entry numbers for the gate screen and the organizer dashboard. */
    public function stats(Event $event): array
    {
        $byStatus = Ticket::where('event_id', $event->id)->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');
        $byType = Ticket::where('tickets.event_id', $event->id)
            ->join('ticket_types', 'ticket_types.id', '=', 'tickets.ticket_type_id')
            ->select('ticket_types.name', DB::raw("sum(case when tickets.status = 'used' then 1 else 0 end) as checked_in"),
                DB::raw("sum(case when tickets.status in ('valid', 'used') then 1 else 0 end) as issued"))
            ->groupBy('ticket_types.name')->get();
        $gates = EventGate::where('event_id', $event->id)->pluck('name', 'id');
        $byGate = TicketScan::where('event_id', $event->id)->whereIn('result', ['admitted', 'manual_admit'])
            ->select('gate_id', DB::raw('count(*) as n'))->groupBy('gate_id')->pluck('n', 'gate_id');
        $rejected = TicketScan::where('event_id', $event->id)->whereNotIn('result', ['admitted', 'manual_admit', 'undo'])
            ->select('result', DB::raw('count(*) as n'))->groupBy('result')->pluck('n', 'result');

        $checkedIn = (int) ($byStatus[Ticket::USED] ?? 0);
        $valid = (int) ($byStatus[Ticket::VALID] ?? 0);

        return [
            'event_id' => $event->id,
            'tickets_issued' => $checkedIn + $valid,
            'checked_in' => $checkedIn,
            'still_to_arrive' => $valid,
            'admitted_last_15_min' => TicketScan::where('event_id', $event->id)->whereIn('result', ['admitted', 'manual_admit'])
                ->where('created_at', '>=', now()->subMinutes(15))->count(),
            'by_ticket_type' => $byType->map(fn ($r) => ['ticket_type' => $r->name, 'issued' => (int) $r->issued, 'checked_in' => (int) $r->checked_in])->values(),
            'by_gate' => $byGate->map(fn ($n, $gateId) => ['gate' => $gateId ? ($gates[$gateId] ?? 'Unknown') : 'No gate', 'admitted' => (int) $n])->values(),
            'rejected_scans' => $rejected->map(fn ($n) => (int) $n),
            'as_of' => now()->toIso8601ZuluString(),
        ];
    }

    /** Ticket sales and the organizer's money for one event, in the base currency. */
    public function sales(Event $event): array
    {
        $cur = config('marketplace.currency');
        $sum = fn (string $type) => Money::of((string) (LedgerEntry::where('event_id', $event->id)->where('type', $type)->sum('amount') ?: '0'), $cur);
        $gross = $sum('sale');
        $commission = $sum('commission')->add($sum('commission_reversal'));
        $refunds = $sum('refund');

        return [
            'event_id' => $event->id,
            'currency' => $cur,
            'ticket_types' => $event->ticketTypes()->get()->map(fn ($t) => [
                'id' => $t->id, 'name' => $t->name, 'price' => $t->priceMoney()->toArray(),
                'quantity_total' => $t->quantity_total, 'sold' => $t->quantity_sold, 'held_in_checkout' => $t->quantity_reserved,
                'available' => $t->available(),
            ])->values(),
            'gross_sales' => $gross->toDecimal(),
            'refunds' => $refunds->multiply(-1)->toDecimal(),
            'platform_commission' => $commission->multiply(-1)->toDecimal(),
            'net_to_organizer' => $gross->add($refunds)->add($commission)->toDecimal(),
            'orders' => Ticket::where('event_id', $event->id)->distinct()->count('order_id'),
        ];
    }

    /** Let someone in without a scan (flat phone battery, damaged QR). The ticket is still used exactly once. */
    public function manualAdmit(Event $event, Ticket $ticket, string $reason, User $by): Ticket
    {
        if ($ticket->event_id !== $event->id) {
            throw new ApiException(404, 'not_found', 'The requested resource was not found.');
        }
        $changed = Ticket::whereKey($ticket->id)->where('status', Ticket::VALID)
            ->update(['status' => Ticket::USED, 'checked_in_at' => now(), 'checked_in_by' => $by->id]);
        if ($changed !== 1) {
            throw new ApiException(409, 'ticket_not_valid', "This ticket is {$ticket->fresh()->status}.");
        }
        TicketScan::create(['event_id' => $event->id, 'ticket_id' => $ticket->id, 'user_id' => $by->id, 'result' => 'manual_admit', 'note' => $reason]);
        $this->audit->record('ticket.manual_admit', $ticket, ['reason' => $reason]);

        return $ticket->fresh();
    }

    /** Reverse a mistaken check-in (scanned the wrong ticket of a group). */
    public function undoCheckIn(Event $event, Ticket $ticket, string $reason, User $by): Ticket
    {
        if ($ticket->event_id !== $event->id) {
            throw new ApiException(404, 'not_found', 'The requested resource was not found.');
        }
        $changed = Ticket::whereKey($ticket->id)->where('status', Ticket::USED)
            ->update(['status' => Ticket::VALID, 'checked_in_at' => null, 'checked_in_by' => null]);
        if ($changed !== 1) {
            throw new ApiException(409, 'not_checked_in', 'This ticket has not been checked in.');
        }
        TicketScan::create(['event_id' => $event->id, 'ticket_id' => $ticket->id, 'user_id' => $by->id, 'result' => 'undo', 'note' => $reason]);
        $this->audit->record('ticket.checkin_undone', $ticket, ['reason' => $reason]);

        return $ticket->fresh();
    }

    /** Partly hide an email for attendee lists: "na****@gmail.com". */
    public static function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 2).str_repeat('*', max(2, mb_strlen($local) - 2)).'@'.$domain;
    }
}
