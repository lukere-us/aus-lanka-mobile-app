<?php

namespace App\Modules\Rentals\Listeners;

use App\Modules\Notifications\Messages\RentalReady;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Rentals\Models\Rental;
use App\Modules\Rentals\Services\RentalIssuer;
use Illuminate\Contracts\Queue\ShouldQueue;

class CreateRentalsForPaidOrder implements ShouldQueue
{
    public string $queue = 'fulfillment';

    public int $tries = 5;

    public function __construct(private readonly RentalIssuer $issuer) {}

    public function handle(OrderPaid $event): void
    {
        if ($this->issuer->issueForOrder($event->orderId) > 0) {
            foreach (Rental::where('order_id', $event->orderId)->with(['movie', 'user', 'package'])->get() as $rental) {
                $rental->user->notify(new RentalReady($rental->id, $rental->movie->title, $rental->watch_hours, $rental->package->start_within_days));
            }
        }
    }
}
