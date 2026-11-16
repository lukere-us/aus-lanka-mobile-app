<?php

namespace App\Modules\Orders\Contracts;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Support\ResolvedItem;

/**
 * Optional second contract for resolvers whose items have limited stock. Called inside the checkout
 * transaction right after the order item is written; throwing rolls the whole order back.
 */
interface ReservesStock
{
    public function reserve(Order $order, OrderItem $item, ResolvedItem $resolved): void;
}
