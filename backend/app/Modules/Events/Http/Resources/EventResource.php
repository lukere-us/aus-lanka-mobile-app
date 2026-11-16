<?php

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Event */
class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $manage = $request->is('api/v1/organizer/*') || $request->is('api/v1/admin/*');

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'image_url' => $this->image_url,
            'starts_at' => $this->starts_at?->toIso8601ZuluString(),
            // Same instant in the venue's own timezone, ready to show ("7:30 pm AEDT").
            'starts_at_local' => $this->whenLoaded('venue', fn () => $this->starts_at?->clone()->setTimezone($this->venue->timezone)->toIso8601String()),
            'ends_at' => $this->ends_at?->toIso8601ZuluString(),
            'doors_open_at' => $this->doors_open_at?->toIso8601ZuluString(),
            'min_age' => $this->min_age,
            'terms' => $this->terms,
            'venue' => $this->whenLoaded('venue', fn () => [
                'id' => $this->venue->id, 'name' => $this->venue->name, 'address' => $this->venue->address,
                'city' => $this->venue->city, 'country' => $this->venue->country, 'timezone' => $this->venue->timezone, 'latitude' => $this->venue->latitude, 'longitude' => $this->venue->longitude,
            ]),
            'organizer' => $this->whenLoaded('organizer', fn () => ['name' => $this->organizer->name, 'slug' => $this->organizer->slug]),
            'ticket_types' => TicketTypeResource::collection($this->whenLoaded('ticketTypes',
                fn () => $manage ? $this->ticketTypes : $this->ticketTypes->where('status', 'active')->values())),
            'status' => $this->when($manage, fn () => $this->status->value),
            'review_note' => $this->when($manage, $this->review_note),
            'published_at' => $this->when($manage, fn () => $this->published_at?->toIso8601ZuluString()),
        ];
    }
}
