<?php

namespace App\Modules\Events\Checkout;

use App\Http\Responses\ApiException;
use App\Models\User;
use App\Modules\Events\Models\TicketType;
use App\Modules\Events\Services\InventoryService;
use App\Modules\Orders\Contracts\PurchasableResolver;
use App\Modules\Orders\Contracts\ReservesStock;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Support\ResolvedItem;

/** Lets checkout sell event tickets / concert passes: {"type": "ticket_type", "id": 12, "quantity": 2}. */
class TicketTypeResolver implements PurchasableResolver, ReservesStock
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function resolve(int|string $id, int $quantity, User $buyer): ResolvedItem
    {
        $type = TicketType::with('event.organizer', 'event.venue')->find($id);
        if (! $type || ! $type->event || ! $type->event->isOnSale()) {
            throw new ApiException(422, 'not_on_sale', 'These tickets are not on sale.');
        }

        $state = $type->saleState();
        if ($state !== 'on_sale') {
            throw new ApiException(409, $state === 'sold_out' ? 'sold_out' : 'not_on_sale', match ($state) {
                'sold_out' => "{$type->name} tickets are sold out.",
                'not_started' => "Sales for {$type->name} have not started yet.",
                'ended' => "Sales for {$type->name} have ended.",
                default => 'These tickets are not on sale.',
            });
        }
        if ($quantity > $type->max_per_order) {
            throw new ApiException(422, 'over_max_per_order', "You can buy up to {$type->max_per_order} {$type->name} ticket(s) per order.");
        }

        return new ResolvedItem(
            name: "{$type->event->title} — {$type->name}",
            unitPrice: $type->priceMoney(),
            quantity: $quantity,
            purchasable: $type,
            metadata: [
                'event_id' => $type->event_id,
                'event_starts_at' => $type->event->starts_at->toIso8601ZuluString(),
                'venue' => $type->event->venue->name,
            ],
        );
    }

    public function reserve(Order $order, OrderItem $item, ResolvedItem $resolved): void
    {
        $this->inventory->reserve($order, $item, $resolved->purchasable->getKey(), $resolved->quantity);
    }
}
