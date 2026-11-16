<?php

namespace App\Modules\Orders\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\OrderExpired;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderExpiryService
{
    public function __construct(private readonly PaymentService $payments, private readonly AuditLogger $audit) {}

    /** Expire unpaid orders past their hold time. Checks the provider first so a late payment is never lost. */
    public function expireDue(int $limit = 500): int
    {
        $expired = 0;
        $due = Order::whereIn('status', [OrderStatus::PendingPayment, OrderStatus::Failed])
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->with('latestPayment')
            ->get();

        foreach ($due as $order) {
            try {
                if ($order->latestPayment && $this->payments->verify($order->latestPayment, 'expiry') === 'paid') {
                    continue;
                }
            } catch (Throwable $e) {
                // Provider unreachable: keep the order open and try again next run rather than expiring a maybe-paid order.
                Log::warning('Expiry check could not reach gateway', ['order' => $order->reference, 'error' => $e->getMessage()]);

                continue;
            }

            $didExpire = DB::transaction(function () use ($order) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if (! $locked->status->canTransitionTo(OrderStatus::Expired) || ! $locked->status->isPayable()) {
                    return false;
                }
                $before = $locked->status;
                $locked->transitionTo(OrderStatus::Expired);
                $this->audit->record('order.expired', $locked, ['before' => $before->value]);
                OrderExpired::dispatch($locked->id);

                return true;
            });
            $expired += (int) $didExpire;
        }

        return $expired;
    }
}
