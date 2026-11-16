<?php

use App\Modules\Orders\OrdersServiceProvider;
use App\Modules\Rentals\RentalsServiceProvider;
use App\Modules\Tickets\TicketingServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    OrdersServiceProvider::class,
    TicketingServiceProvider::class,
    RentalsServiceProvider::class,
];
