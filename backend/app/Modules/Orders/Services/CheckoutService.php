<?php

namespace App\Modules\Orders\Services;

use App\Http\Responses\ApiException;
use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\OrderReference;
use App\Modules\Orders\Support\PurchasableRegistry;
use App\Modules\Orders\Support\ResolvedItem;
use App\Modules\Payments\Exceptions\GatewayUnavailable;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function __construct(
        private readonly PurchasableRegistry $registry,
        private readonly PaymentService $payments,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Create an order + payment and open the provider checkout. Replaying the same Idempotency-Key with
     * the same items returns the original order instead of charging twice.
     *
     * @param  list<array{type: string, id: int|string, quantity: int}>  $items
     * @return array{0: Order, 1: bool} [order, created]
     */
    public function checkout(User $buyer, array $items, string $idempotencyKey): array
    {
        $hash = hash('sha256', json_encode(array_map(
            fn ($i) => [$i['type'], (string) $i['id'], (int) $i['quantity']], $items,
        )));

        if ($existing = $this->findByKey($buyer, $idempotencyKey, $hash)) {
            return [$existing, false];
        }

        $resolved = $this->resolve($buyer, $items);
        $currency = config('marketplace.currency');
        $subtotal = Money::ofMinor(0, $currency);
        foreach ($resolved as $index => $item) {
            if ($item->unitPrice->currency !== $currency) {
                throw ValidationException::withMessages(["items.$index" => ["Only {$currency} prices can be checked out."]]);
            }
            $subtotal = $subtotal->add($item->lineTotal());
        }
        // Service fees and discounts plug in here (D07, D08); zero for now.
        $fees = Money::ofMinor(0, $currency);
        $discount = Money::ofMinor(0, $currency);
        $total = $subtotal->add($fees)->subtract($discount);

        try {
            [$order, $payment] = DB::transaction(function () use ($buyer, $items, $resolved, $idempotencyKey, $hash, $subtotal, $fees, $discount, $total, $currency) {
                $order = Order::create([
                    'reference' => OrderReference::generate(),
                    'user_id' => $buyer->id,
                    'status' => OrderStatus::PendingPayment,
                    'currency' => $currency,
                    'subtotal' => $subtotal->toDecimal(),
                    'fee_total' => $fees->toDecimal(),
                    'discount_total' => $discount->toDecimal(),
                    'total' => $total->toDecimal(),
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $hash,
                    'expires_at' => now()->addMinutes(config('payments.order_ttl_minutes')),
                ]);

                foreach ($resolved as $index => $item) {
                    $order->items()->create([
                        'item_type' => $items[$index]['type'],
                        'purchasable_type' => $item->purchasable?->getMorphClass(),
                        'purchasable_id' => $item->purchasable?->getKey(),
                        'name' => $item->name,
                        'unit_price' => $item->unitPrice->toDecimal(),
                        'quantity' => $item->quantity,
                        'line_total' => $item->lineTotal()->toDecimal(),
                        'currency' => $item->unitPrice->currency,
                        'metadata' => $item->metadata ?: null,
                    ]);
                }

                $this->audit->record('order.created', $order, ['total' => $total->toArray()]);

                return [$order, $this->payments->initiate($order)];
            });
        } catch (UniqueConstraintViolationException) {
            // Two identical requests raced; the other one created the order.
            return [$this->findByKey($buyer, $idempotencyKey, $hash) ?? throw new ApiException(409, 'conflict', 'Please retry.'), false];
        }

        $this->openCheckout($payment);

        return [$order->fresh(['items', 'latestPayment']), true];
    }

    /** New payment attempt for an order whose last attempt failed or was abandoned. */
    public function retryPayment(Order $order): Order
    {
        if (! $order->status->isPayable() || $order->expires_at?->isPast()) {
            throw new ApiException(409, 'order_not_payable', 'This order can no longer be paid. Please start a new order.');
        }
        $latest = $order->latestPayment;
        if ($latest && $latest->status === Payment::INITIATED && $latest->checkout_url) {
            return $order->fresh(['items', 'latestPayment']); // still usable: reuse it
        }

        $payment = DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === OrderStatus::Failed) {
                $locked->transitionTo(OrderStatus::PendingPayment);
            }

            return $this->payments->initiate($locked);
        });
        $this->openCheckout($payment);

        return $order->fresh(['items', 'latestPayment']);
    }

    private function openCheckout(Payment $payment): void
    {
        try {
            $this->payments->startCheckout($payment);
        } catch (GatewayUnavailable) {
            throw new ApiException(502, 'payment_gateway_unavailable',
                'We could not reach the payment provider. Your order is saved; please try paying again shortly.');
        }
    }

    private function findByKey(User $buyer, string $key, string $hash): ?Order
    {
        $existing = Order::where('user_id', $buyer->id)->where('idempotency_key', $key)->with(['items', 'latestPayment'])->first();
        if ($existing && $existing->request_hash !== $hash) {
            throw new ApiException(409, 'idempotency_conflict', 'This Idempotency-Key was already used for a different order.');
        }

        return $existing;
    }

    /**
     * @param  list<array{type: string, id: int|string, quantity: int}>  $items
     * @return list<ResolvedItem>
     */
    private function resolve(User $buyer, array $items): array
    {
        $resolved = [];
        foreach ($items as $index => $item) {
            if (! $this->registry->has($item['type'])) {
                throw ValidationException::withMessages(["items.$index.type" => ['This item type cannot be purchased.']]);
            }
            $resolved[] = $this->registry->resolver($item['type'])->resolve($item['id'], (int) $item['quantity'], $buyer);
        }

        return $resolved;
    }
}
