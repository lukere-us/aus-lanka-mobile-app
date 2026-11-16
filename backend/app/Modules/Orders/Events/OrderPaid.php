<?php

namespace App\Modules\Orders\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired exactly once, after the transaction that marked the order paid commits.
 * Rentals and Tickets listen to this to fulfil (create rentals, issue tickets).
 */
class OrderPaid implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $orderId) {}
}
