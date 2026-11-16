<?php

namespace App\Modules\Events\Services;

use App\Http\Responses\ApiException;
use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\TicketType;
use App\Modules\Events\Models\Venue;
use App\Modules\Organizers\Models\Organizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(Organizer $organizer, array $data): Event
    {
        $this->assertVenueUsable($organizer, (int) $data['venue_id']);

        $event = Event::create($data + [
            'organizer_id' => $organizer->id,
            'slug' => Str::limit(Str::slug($data['title']), 100, '').'-'.Str::lower(Str::random(6)),
            'status' => EventStatus::Draft,
        ]);
        $this->audit->record('event.created', $event);

        return $event;
    }

    public function update(Event $event, array $data): Event
    {
        if (! $event->status->isEditableByOrganizer()) {
            throw new ApiException(409, 'event_locked', "An event that is {$event->status->value} cannot be edited.");
        }
        if (isset($data['venue_id'])) {
            $this->assertVenueUsable($event->organizer, (int) $data['venue_id']);
        }
        $before = $event->only(array_keys($data));
        $event->update($data);
        $this->audit->record('event.updated', $event, ['before' => $before, 'after' => $event->only(array_keys($data))]);

        return $event;
    }

    public function submit(Event $event): Event
    {
        if ($event->status !== EventStatus::Draft) {
            throw new ApiException(409, 'invalid_status_change', 'Only draft events can be submitted for review.');
        }
        if (! $event->organizer->isApproved()) {
            throw new ApiException(403, 'organizer_not_approved', 'Your organizer account must be approved before events can be published.');
        }
        if (! $event->ticketTypes()->where('status', 'active')->exists()) {
            throw new ApiException(422, 'no_ticket_types', 'Add at least one ticket type before submitting.');
        }
        if ($event->starts_at->isPast()) {
            throw new ApiException(422, 'event_in_past', 'The event start time has passed.');
        }

        $event->update(['status' => EventStatus::PendingReview, 'review_note' => null]);
        $this->audit->record('event.submitted', $event);

        return $event;
    }

    public function review(Event $event, bool $approve, ?string $note, User $admin): Event
    {
        if ($event->status !== EventStatus::PendingReview) {
            throw new ApiException(409, 'invalid_status_change', 'Only events awaiting review can be approved or rejected.');
        }
        if ($approve && ! $event->organizer->isApproved()) {
            throw new ApiException(409, 'organizer_not_approved', 'Approve the organizer first.');
        }

        $event->update($approve
            ? ['status' => EventStatus::Published, 'published_at' => now(), 'review_note' => $note]
            : ['status' => EventStatus::Draft, 'review_note' => $note]);
        $this->audit->record($approve ? 'event.published' : 'event.rejected', $event, ['note' => $note, 'by' => $admin->id]);

        return $event;
    }

    public function addTicketType(Event $event, array $data): TicketType
    {
        $this->assertEditable($event);
        $type = $event->ticketTypes()->create($data + ['currency' => config('marketplace.currency')]);
        $this->audit->record('ticket_type.created', $type, ['event_id' => $event->id, 'quantity_total' => $type->quantity_total, 'price' => $type->price]);

        return $type;
    }

    public function updateTicketType(TicketType $type, array $data): TicketType
    {
        $this->assertEditable($type->event);

        return DB::transaction(function () use ($type, $data) {
            $locked = TicketType::whereKey($type->id)->lockForUpdate()->firstOrFail();
            $committed = $locked->quantity_sold + $locked->quantity_reserved;
            if (isset($data['quantity_total']) && (int) $data['quantity_total'] < $committed) {
                throw new ApiException(422, 'quantity_below_committed',
                    "{$committed} ticket(s) are already sold or held, so the total cannot go below that.");
            }
            $before = $locked->only(array_keys($data));
            $locked->update($data);
            $this->audit->record('ticket_type.updated', $locked, ['before' => $before, 'after' => $locked->only(array_keys($data))]);

            return $locked;
        });
    }

    private function assertEditable(Event $event): void
    {
        if (! $event->status->isEditableByOrganizer()) {
            throw new ApiException(409, 'event_locked', "Ticket setup cannot change while the event is {$event->status->value}.");
        }
    }

    private function assertVenueUsable(Organizer $organizer, int $venueId): void
    {
        $venue = Venue::findOrFail($venueId);
        if ($venue->organizer_id !== null && $venue->organizer_id !== $organizer->id) {
            throw new ApiException(422, 'venue_not_available', 'That venue belongs to another organizer.');
        }
    }
}
