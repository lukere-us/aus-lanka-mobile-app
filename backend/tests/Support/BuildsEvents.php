<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\TicketType;
use App\Modules\Events\Models\Venue;
use App\Modules\Identity\Models\Role;
use App\Modules\Organizers\Models\Organizer;
use Illuminate\Support\Str;

trait BuildsEvents
{
    protected function approvedOrganizer(?User $owner = null): Organizer
    {
        $owner ??= $this->userWithRole(Role::ORGANIZER);
        $organizer = Organizer::create([
            'owner_id' => $owner->id, 'name' => 'Colombo Live', 'slug' => 'colombo-live-'.Str::random(4),
            'contact_email' => 'events@colombolive.lk', 'status' => Organizer::APPROVED, 'approved_at' => now(),
        ]);
        $organizer->members()->attach($owner->id, ['role' => Organizer::ROLE_OWNER]);

        return $organizer;
    }

    /** A published event 30 days out with one ticket type (price AUD 2,500, $stock tickets). */
    protected function publishedEvent(int $stock = 100, ?Organizer $organizer = null, array $typeAttributes = []): TicketType
    {
        $organizer ??= $this->approvedOrganizer();
        $venue = Venue::create(['name' => 'Nelum Pokuna', 'address' => 'Colombo 07', 'city' => 'Colombo']);
        $event = Event::create([
            'organizer_id' => $organizer->id, 'venue_id' => $venue->id, 'slug' => 'live-'.Str::lower(Str::random(6)),
            'title' => 'Live at Nelum Pokuna', 'category' => 'concert', 'starts_at' => now()->addDays(30),
            'status' => EventStatus::Published, 'published_at' => now(),
        ]);

        return $event->ticketTypes()->create(array_merge([
            'name' => 'General', 'kind' => 'general', 'price' => '2500.00', 'currency' => 'AUD',
            'quantity_total' => $stock, 'max_per_order' => 10,
        ], $typeAttributes));
    }

    protected function buyTickets(TicketType $type, int $quantity, ?User $buyer = null)
    {
        return $this->checkout([['type' => 'ticket_type', 'id' => $type->id, 'quantity' => $quantity]], as: $buyer);
    }
}
