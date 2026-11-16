<?php

namespace App\Modules\Orders\Console;

use App\Modules\Orders\Services\OrderExpiryService;
use Illuminate\Console\Command;

class ExpirePendingOrders extends Command
{
    protected $signature = 'orders:expire-pending {--limit=500}';

    protected $description = 'Expire unpaid orders past their hold time (checks the payment provider first).';

    public function handle(OrderExpiryService $expiry): int
    {
        $count = $expiry->expireDue((int) $this->option('limit'));
        $this->info("Expired {$count} order(s).");

        return self::SUCCESS;
    }
}
