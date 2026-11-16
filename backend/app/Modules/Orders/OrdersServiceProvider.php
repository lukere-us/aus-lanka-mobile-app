<?php

namespace App\Modules\Orders;

use App\Modules\Orders\Console\ExpirePendingOrders;
use App\Modules\Orders\Support\PurchasableRegistry;
use App\Modules\Payments\Services\PaymentGatewayManager;
use Illuminate\Support\ServiceProvider;

class OrdersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PurchasableRegistry::class);
        $this->app->singleton(PaymentGatewayManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpirePendingOrders::class]);
        }
        // Sellable modules register here as they arrive, e.g.:
        // $this->app->make(PurchasableRegistry::class)->register('rental_package', RentalPackageResolver::class);
    }
}
