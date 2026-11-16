<?php

namespace App\Modules\Rentals\Listeners;

use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Rentals\Services\RentalIssuer;
use Illuminate\Contracts\Queue\ShouldQueue;

class CreateRentalsForPaidOrder implements ShouldQueue
{
    public string $queue = 'fulfillment';

    public int $tries = 5;

    public function __construct(private readonly RentalIssuer $issuer) {}

    public function handle(OrderPaid $event): void
    {
        $this->issuer->issueForOrder($event->orderId);
    }
}
