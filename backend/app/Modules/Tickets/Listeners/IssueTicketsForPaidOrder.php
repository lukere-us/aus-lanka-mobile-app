<?php

namespace App\Modules\Tickets\Listeners;

use App\Modules\Notifications\Messages\TicketsReady;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Services\TicketIssuer;
use Illuminate\Contracts\Queue\ShouldQueue;

class IssueTicketsForPaidOrder implements ShouldQueue
{
    public string $queue = 'fulfillment';

    public int $tries = 5;

    public function __construct(private readonly TicketIssuer $issuer) {}

    public function handle(OrderPaid $event): void
    {
        if ($this->issuer->issueForOrder($event->orderId) > 0) {
            $tickets = Ticket::where('order_id', $event->orderId)->with(['event', 'holder'])->get();
            foreach ($tickets->groupBy('event_id') as $group) {
                $group->first()->holder->notify(new TicketsReady($event->orderId, $group->count(), $group->first()->event->title));
            }
        }
    }
}
