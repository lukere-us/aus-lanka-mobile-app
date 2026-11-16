<?php

namespace App\Modules\Tickets\Services;

use App\Http\Responses\ApiException;
use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Events\Models\Event;
use App\Modules\Organizers\Services\OrganizerContext;
use App\Modules\Tickets\Models\Ticket;

/** Gate check-in (G03). The UPDATE ... WHERE status = 'valid' is atomic, so two scanners can never admit one ticket twice. */
class CheckInService
{
    public function __construct(private readonly OrganizerContext $organizers, private readonly AuditLogger $audit) {}

    public function checkIn(User $staff, string $qrToken, int $eventId): Ticket
    {
        $event = Event::findOrFail($eventId);
        $this->authorize($staff, $event);

        $ticket = Ticket::where('qr_token', $qrToken)->first();
        if (! $ticket) {
            throw new ApiException(404, 'ticket_not_found', 'This QR code is not a valid ticket.');
        }
        if ($ticket->event_id !== $event->id) {
            throw new ApiException(409, 'wrong_event', 'This ticket is for a different event.');
        }

        $admitted = Ticket::whereKey($ticket->id)->where('status', Ticket::VALID)
            ->update(['status' => Ticket::USED, 'checked_in_at' => now(), 'checked_in_by' => $staff->id]);

        $ticket->refresh();
        if ($admitted !== 1) {
            if ($ticket->status === Ticket::USED) {
                throw new ApiException(409, 'ticket_already_used', 'This ticket has already been scanned.', [
                    'checked_in_at' => $ticket->checked_in_at?->toIso8601ZuluString(),
                ]);
            }
            throw new ApiException(409, 'ticket_not_valid', "This ticket is {$ticket->status}.", ['status' => $ticket->status]);
        }

        $this->audit->record('ticket.checked_in', $ticket, ['event_id' => $event->id]);

        return $ticket;
    }

    private function authorize(User $staff, Event $event): void
    {
        if ($staff->can('events.manage')) {
            return;
        }
        if ($staff->can('scanner.checkin') && $this->organizers->isMember($staff, $event->organizer_id)) {
            return;
        }

        throw new ApiException(403, 'forbidden', 'You are not assigned to scan for this event.');
    }
}
