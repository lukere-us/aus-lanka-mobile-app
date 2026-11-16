<?php

namespace App\Modules\Tickets\Listeners;

use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Tickets\Services\TicketIssuer;
use Illuminate\Contracts\Queue\ShouldQueue;

class IssueTicketsForPaidOrder implements ShouldQueue
{
    public string $queue = 'fulfillment';

    public int $tries = 5;

    public function __construct(private readonly TicketIssuer $issuer) {}

    public function handle(OrderPaid $event): void
    {
        $this->issuer->issueForOrder($event->orderId);
    }
}
