<?php

namespace App\Modules\Tickets\Http\Resources;

use App\Modules\Tickets\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Ticket */
class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isHolder = $request->user()?->id === $this->user_id;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'number' => $this->sequence,
            'ticket_type' => $this->whenLoaded('ticketType', fn () => ['name' => $this->ticketType->name, 'kind' => $this->ticketType->kind]),
            'event' => $this->whenLoaded('event', fn () => [
                'title' => $this->event->title,
                'slug' => $this->event->slug,
                'starts_at' => $this->event->starts_at->toIso8601ZuluString(),
                'venue' => $this->event->relationLoaded('venue') ? $this->event->venue->name : null,
            ]),
            'order_reference' => $this->whenLoaded('order', fn () => $this->order->reference),
            // Only the holder ever receives the QR value; it is shown only while the ticket can still be used.
            'qr' => $this->when($isHolder && $this->status === 'valid', $this->qr_token),
            'checked_in_at' => $this->checked_in_at?->toIso8601ZuluString(),
        ];
    }
}
