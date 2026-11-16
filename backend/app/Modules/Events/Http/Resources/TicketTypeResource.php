<?php

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\TicketType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TicketType */
class TicketTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $manage = $request->is('api/v1/organizer/*') || $request->is('api/v1/admin/*');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'description' => $this->description,
            'price' => $this->priceMoney()->toArray(),
            'max_per_order' => $this->max_per_order,
            'sale_starts_at' => $this->sale_starts_at?->toIso8601ZuluString(),
            'sale_ends_at' => $this->sale_ends_at?->toIso8601ZuluString(),
            'sale_state' => $this->saleState(),
            'available' => $this->available(),
            'status' => $this->when($manage, $this->status),
            'quantity_total' => $this->when($manage, $this->quantity_total),
            'quantity_sold' => $this->when($manage, $this->quantity_sold),
            'quantity_reserved' => $this->when($manage, $this->quantity_reserved),
        ];
    }
}
