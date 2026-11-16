<?php

namespace App\Modules\Payments\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Data\GatewayStatus;
use App\Modules\Payments\Exceptions\GatewayUnavailable;
use App\Modules\Payments\Jobs\ProcessPaymentEvent;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentService
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly AuditLogger $audit,
    ) {}

    /** Create the payment row for an order (inside the checkout transaction). */
    public function initiate(Order $order): Payment
    {
        return $order->payments()->create([
            'provider' => $this->gateways->driver()->name(),
            'status' => Payment::INITIATED,
            'amount' => $order->total,
            'currency' => $order->currency,
        ]);
    }

    /** Open the hosted checkout at the provider (after the order transaction commits). */
    public function startCheckout(Payment $payment): Payment
    {
        try {
            $session = $this->gateways->driver($payment->provider)->createCheckout($payment->order, $payment);
        } catch (Throwable $e) {
            report($e);
            $payment->forceFill(['status' => Payment::FAILED, 'failure_reason' => 'gateway_unavailable'])->save();

            throw new GatewayUnavailable('The payment provider could not be reached.', previous: $e);
        }

        $payment->forceFill([
            'provider_reference' => $session->providerReference,
            'checkout_url' => $session->redirectUrl,
        ])->save();

        return $payment;
    }

    /**
     * Webhook entry point: authenticate, store once in the inbox, queue processing.
     * Returns 'accepted' or 'duplicate'. Throws InvalidWebhookSignature for forged/garbled requests.
     *
     * @param  array<string, string>  $headers  lower-cased
     */
    public function receiveWebhook(string $provider, string $rawBody, array $headers): string
    {
        $notification = $this->gateways->driver($provider)->parseWebhook($rawBody, $headers);

        try {
            $event = PaymentEvent::firstOrCreate(
                ['provider' => $provider, 'event_id' => $notification->eventId],
                [
                    'type' => $notification->type,
                    'provider_reference' => $notification->providerReference,
                    'payload' => $notification->payload,
                    'attempts' => 0,
                ],
            );
        } catch (UniqueConstraintViolationException) {
            return 'duplicate'; // a concurrent delivery of the same event won the insert
        }

        if (! $event->wasRecentlyCreated && $event->processed_at !== null) {
            return 'duplicate';
        }

        ProcessPaymentEvent::dispatch($event->id);

        return 'accepted';
    }

    /** Process one inbox event exactly once. Safe to retry. */
    public function processEvent(int $eventId): void
    {
        $event = PaymentEvent::find($eventId);
        if (! $event || $event->processed_at !== null) {
            return;
        }

        $event->increment('attempts');

        $payment = Payment::where('provider', $event->provider)
            ->where('provider_reference', $event->provider_reference)
            ->first();

        if (! $payment) {
            $event->forceFill(['processed_at' => now(), 'outcome' => 'unknown_payment'])->save();
            Log::warning('Payment webhook for unknown reference', ['provider' => $event->provider, 'reference' => $event->provider_reference]);

            return;
        }

        try {
            $outcome = $this->verify($payment, 'webhook');
        } catch (Throwable $e) {
            $event->forceFill(['payment_id' => $payment->id, 'last_error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            throw $e; // the job retries with backoff
        }

        $event->forceFill(['payment_id' => $payment->id, 'processed_at' => now(), 'outcome' => $outcome, 'last_error' => null])->save();
    }

    /**
     * Ask the provider for the true status and apply it. Used by webhooks, the app's
     * "check payment" call and the expiry job. Returns what happened, for logs/tests.
     */
    public function verify(Payment $payment, string $source): string
    {
        if ($payment->isFinal()) {
            return 'already_final';
        }
        if ($payment->provider_reference === null) {
            return 'not_started';
        }

        $status = $this->gateways->driver($payment->provider)->fetchStatus($payment);

        return match ($status->status) {
            GatewayStatus::SUCCEEDED => $this->markSucceeded($payment, $status, $source),
            GatewayStatus::FAILED, GatewayStatus::CANCELLED => $this->markFailed($payment, $status, $source),
            default => 'pending',
        };
    }

    private function markSucceeded(Payment $payment, GatewayStatus $status, string $source): string
    {
        return DB::transaction(function () use ($payment, $status, $source) {
            /** @var Payment $locked */
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->isFinal()) {
                return 'already_final';
            }
            /** @var Order $order */
            $order = Order::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();

            $expected = $locked->amountMoney();
            $matches = $status->amount !== null
                && $status->amount->currency === $expected->currency
                && $status->amount->minor === $expected->minor;

            if (! $matches) {
                $locked->forceFill(['status' => Payment::REVIEW, 'failure_reason' => 'amount_mismatch', 'verified_at' => now()])->save();
                $this->audit->record('payment.amount_mismatch', $locked, [
                    'expected' => $expected->toArray(), 'reported' => $status->amount?->toArray(), 'source' => $source,
                ]);

                return 'amount_mismatch';
            }

            $locked->forceFill(['status' => Payment::SUCCEEDED, 'verified_at' => now(), 'failure_reason' => null,
                'provider_payment_id' => $status->providerPaymentId ?? $locked->provider_payment_id])->save();
            $this->audit->record('payment.succeeded', $locked, ['amount' => $expected->toArray(), 'source' => $source]);

            $before = $order->status;
            if ($order->status !== OrderStatus::Paid && $order->status->canTransitionTo(OrderStatus::Paid)) {
                $order->transitionTo(OrderStatus::Paid);
                $this->audit->record('order.paid', $order, ['before' => $before->value, 'payment_id' => $locked->id]);
                OrderPaid::dispatch($order->id);

                return 'paid';
            }

            // Paid twice for one order (e.g. two checkout attempts both completed): flag for refund.
            $this->audit->record('payment.duplicate_for_order', $locked, ['order_status' => $order->status->value]);

            return 'duplicate_payment';
        });
    }

    private function markFailed(Payment $payment, GatewayStatus $status, string $source): string
    {
        return DB::transaction(function () use ($payment, $status, $source) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->isFinal() || $locked->status === $status->status) {
                return 'already_final';
            }
            $order = Order::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();

            $locked->forceFill(['status' => $status->status, 'failure_reason' => $status->reason, 'verified_at' => now()])->save();
            $this->audit->record('payment.'.$status->status, $locked, ['reason' => $status->reason, 'source' => $source]);

            if ($order->status === OrderStatus::PendingPayment) {
                $order->transitionTo(OrderStatus::Failed);
            }

            return 'failed';
        });
    }
}
