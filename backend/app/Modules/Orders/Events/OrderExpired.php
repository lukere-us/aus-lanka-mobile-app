<?php

namespace App\Modules\Orders\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired when an unpaid order times out, so reserved ticket stock can be released (F05). */
class OrderExpired implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $orderId) {}
}
