<?php

use App\Modules\Orders\OrdersServiceProvider;
use App\Modules\Tickets\TicketingServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    OrdersServiceProvider::class,
    TicketingServiceProvider::class,
];
