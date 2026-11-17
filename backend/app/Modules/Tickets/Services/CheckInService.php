<?php

namespace App\Modules\Tickets\Services;

use App\Http\Responses\ApiException;
use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\EventOps\Models\EventGate;
use App\Modules\EventOps\Models\EventStaff;
use App\Modules\EventOps\Models\ScannerDevice;
use App\Modules\EventOps\Models\TicketScan;
use App\Modules\Events\Models\Event;
use App\Modules\Organizers\Models\Organizer;
use App\Modules\Organizers\Services\OrganizerContext;
use App\Modules\Tickets\Models\Ticket;

/**
 * Gate check-in (G03) with staff/gate/device rules (G01) and a scan log (G04).
 * The UPDATE ... WHERE status = 'valid' is atomic, so two scanners can never admit one ticket twice.
 * Every attempt, admitted or rejected, is written to ticket_scans.
 */
class CheckInService
{
    public function __construct(private readonly OrganizerContext $organizers, private readonly AuditLogger $audit) {}

    public function checkIn(User $staff, string $qrToken, int $eventId, ?int $gateId = null, ?int $deviceId = null): Ticket
    {
        $event = Event::findOrFail($eventId);
        $gateId = $this->authorize($staff, $event, $gateId);
        $device = $this->device($event, $deviceId);
        $log = fn (string $result, ?int $ticketId = null) => TicketScan::create([
            'event_id' => $event->id, 'ticket_id' => $ticketId, 'gate_id' => $gateId, 'user_id' => $staff->id,
            'device_id' => $device?->id, 'result' => $result,
        ]);

        $ticket = Ticket::where('qr_token', $qrToken)->first();
        if (! $ticket) {
            $log('not_found');
            throw new ApiException(404, 'ticket_not_found', 'This QR code is not a valid ticket.');
        }
        if ($ticket->event_id !== $event->id) {
            $log('wrong_event', $ticket->id);
            throw new ApiException(409, 'wrong_event', 'This ticket is for a different event.');
        }

        $admitted = Ticket::whereKey($ticket->id)->where('status', Ticket::VALID)
            ->update(['status' => Ticket::USED, 'checked_in_at' => now(), 'checked_in_by' => $staff->id]);

        $ticket->refresh();
        if ($admitted !== 1) {
            if ($ticket->status === Ticket::USED) {
                $log('already_used', $ticket->id);
                throw new ApiException(409, 'ticket_already_used', 'This ticket has already been scanned.', [
                    'checked_in_at' => $ticket->checked_in_at?->toIso8601ZuluString(),
                ]);
            }
            $log('not_valid', $ticket->id);
            throw new ApiException(409, 'ticket_not_valid', "This ticket is {$ticket->status}.", ['status' => $ticket->status]);
        }

        $log('admitted', $ticket->id);
        $device?->forceFill(['last_used_at' => now()])->save();
        $this->audit->record('ticket.checked_in', $ticket, ['event_id' => $event->id, 'gate_id' => $gateId, 'device_id' => $device?->id]);

        return $ticket;
    }

    /** @return int|null the gate the scan counts for */
    private function authorize(User $staff, Event $event, ?int $gateId): ?int
    {
        if ($gateId !== null && ! EventGate::where('event_id', $event->id)->whereKey($gateId)->exists()) {
            throw new ApiException(422, 'unknown_gate', 'That gate does not belong to this event.');
        }
        if ($staff->can('events.manage')) {
            return $gateId;
        }
        // Organizer owners and managers may scan at any gate of their own events.
        if ($this->organizers->isMember($staff, $event->organizer_id, [Organizer::ROLE_OWNER, Organizer::ROLE_MANAGER])) {
            return $gateId;
        }
        // Gate staff only at events they are assigned to, and only at their gate if they have one.
        $assignment = $staff->can('scanner.checkin') && $this->organizers->isMember($staff, $event->organizer_id)
            ? EventStaff::where('event_id', $event->id)->where('user_id', $staff->id)->first()
            : null;
        if (! $assignment) {
            throw new ApiException(403, 'forbidden', 'You are not assigned to scan for this event.');
        }
        if ($assignment->gate_id !== null && $gateId !== null && $gateId !== $assignment->gate_id) {
            throw new ApiException(403, 'wrong_gate', 'You are assigned to a different gate.');
        }

        return $gateId ?? $assignment->gate_id;
    }

    private function device(Event $event, ?int $deviceId): ?ScannerDevice
    {
        if ($deviceId === null) {
            return null;
        }
        $device = ScannerDevice::where('organizer_id', $event->organizer_id)->find($deviceId);
        if (! $device || $device->revoked_at) {
            throw new ApiException(403, 'device_revoked', 'This scanning device is not registered or has been revoked.');
        }

        return $device;
    }
}
