<?php

namespace App\Modules\Tickets\Listeners;

use App\Modules\Events\Services\InventoryService;
use App\Modules\Orders\Events\OrderExpired;

class ReleaseReservationsForExpiredOrder
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function handle(OrderExpired $event): void
    {
        $this->inventory->releaseForOrder($event->orderId);
    }
}
