<?php

namespace App\Modules\Rentals;

use App\Modules\Movies\Checkout\RentalPackageResolver;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Support\PurchasableRegistry;
use App\Modules\Rentals\Console\DemoRentalLink;
use App\Modules\Rentals\Console\ExpireRentals;
use App\Modules\Rentals\Listeners\CreateRentalsForPaidOrder;
use App\Modules\Rentals\Video\VideoProviderManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Wires movie rentals into checkout: sells "rental_package" items and creates rentals once paid. */
class RentalsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(VideoProviderManager::class);
    }

    public function boot(): void
    {
        $this->app->make(PurchasableRegistry::class)->register('rental_package', RentalPackageResolver::class);
        Event::listen(OrderPaid::class, CreateRentalsForPaidOrder::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ExpireRentals::class, DemoRentalLink::class]);
        }
    }
}
