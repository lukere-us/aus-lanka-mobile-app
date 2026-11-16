<?php

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Services\PaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ProcessPaymentEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public function __construct(public readonly int $eventId)
    {
        $this->onQueue('payments');
    }

    /** @return list<int> seconds between retries */
    public function backoff(): array
    {
        return [10, 30, 120, 600, 1800];
    }

    public function handle(PaymentService $payments): void
    {
        $payments->processEvent($this->eventId);
    }
}
