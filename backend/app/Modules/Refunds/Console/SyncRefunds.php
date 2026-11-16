<?php

namespace App\Modules\Refunds\Console;

use App\Modules\Refunds\Services\RefundService;
use Illuminate\Console\Command;

class SyncRefunds extends Command
{
    protected $signature = 'refunds:sync';

    protected $description = 'Settle refunds the payment provider was still processing.';

    public function handle(RefundService $refunds): int
    {
        $this->info('Settled '.$refunds->syncPending().' refund(s).');

        return self::SUCCESS;
    }
}
