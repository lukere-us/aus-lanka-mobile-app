<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Notifications\Messages\OrderReceipt;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Models\Order;

class SendOrderReceipt
{
    public function handle(OrderPaid $event): void
    {
        Order::with('user')->find($event->orderId)?->user?->notify(new OrderReceipt($event->orderId));
    }
}
