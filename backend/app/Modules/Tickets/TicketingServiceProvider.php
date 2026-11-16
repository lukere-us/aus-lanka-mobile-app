<?php

namespace App\Modules\Tickets;

use App\Modules\Events\Checkout\TicketTypeResolver;
use App\Modules\Orders\Events\OrderExpired;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Support\PurchasableRegistry;
use App\Modules\Tickets\Listeners\IssueTicketsForPaidOrder;
use App\Modules\Tickets\Listeners\ReleaseReservationsForExpiredOrder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Wires events/ticketing into checkout: sells "ticket_type" items, issues tickets on payment, releases holds on expiry. */
class TicketingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(PurchasableRegistry::class)->register('ticket_type', TicketTypeResolver::class);

        Event::listen(OrderPaid::class, IssueTicketsForPaidOrder::class);
        Event::listen(OrderExpired::class, ReleaseReservationsForExpiredOrder::class);
    }
}
