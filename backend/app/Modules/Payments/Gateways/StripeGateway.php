<?php

namespace App\Modules\Payments\Gateways;

use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\CheckoutSession;
use App\Modules\Payments\Data\GatewayStatus;
use App\Modules\Payments\Data\RefundResult;
use App\Modules\Payments\Data\WebhookNotification;
use App\Modules\Payments\Exceptions\InvalidWebhookSignature;
use App\Modules\Payments\Models\Payment;
use App\Support\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Stripe Checkout (hosted payment page: cards, Apple Pay, Google Pay) via Stripe's REST API.
 *
 * - createCheckout: POST /v1/checkout/sessions, idempotent per payment row.
 * - parseWebhook:   verifies the Stripe-Signature header (HMAC-SHA256 of "{t}.{body}", 5-minute tolerance).
 * - fetchStatus:    GET /v1/checkout/sessions/{id} - the server-side source of truth used before fulfilling.
 * - cancelCheckout: POST /v1/checkout/sessions/{id}/expire when our order expires first.
 */
class StripeGateway implements PaymentGateway
{
    private const API = 'https://api.stripe.com/v1';

    private const TOLERANCE_SECONDS = 300;

    /** Stripe requires a Checkout Session to live at least 30 minutes; we expire it ourselves sooner if needed. */
    private const MIN_SESSION_MINUTES = 31;

    /** @param array{secret_key: ?string, webhook_secret: ?string, success_url: string, cancel_url: string} $config */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function createCheckout(Order $order, Payment $payment): CheckoutSession
    {
        $amount = $payment->amountMoney();
        $order->loadMissing('items', 'user');
        $description = $order->items->map(fn ($i) => "{$i->quantity} × {$i->name}")->implode(', ');

        $response = $this->client()
            ->withHeaders(['Idempotency-Key' => "checkout-payment-{$payment->id}"])
            ->post(self::API.'/checkout/sessions', [
                'mode' => 'payment',
                'client_reference_id' => $order->reference,
                'customer_email' => $order->user?->email,
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($amount->currency),
                        'unit_amount' => $amount->minor,
                        'product_data' => [
                            'name' => "Order {$order->reference}",
                            'description' => mb_substr($description, 0, 500),
                        ],
                    ],
                ]],
                'metadata' => ['order_reference' => $order->reference, 'payment_id' => (string) $payment->id],
                'payment_intent_data' => ['metadata' => ['order_reference' => $order->reference, 'payment_id' => (string) $payment->id]],
                'success_url' => $this->returnUrl($this->config['success_url'], $order),
                'cancel_url' => $this->returnUrl($this->config['cancel_url'], $order),
                'expires_at' => max($order->expires_at?->getTimestamp() ?? 0, now()->addMinutes(self::MIN_SESSION_MINUTES)->getTimestamp()),
            ]);

        if (! $response->successful() || ! $response->json('id') || ! $response->json('url')) {
            throw new RuntimeException('Stripe checkout session failed: '.$response->status().' '.$response->json('error.message'));
        }

        return new CheckoutSession((string) $response->json('url'), (string) $response->json('id'));
    }

    public function parseWebhook(string $rawBody, array $headers): WebhookNotification
    {
        $secret = (string) ($this->config['webhook_secret'] ?? '');
        $header = (string) ($headers['stripe-signature'] ?? '');
        if ($secret === '' || $header === '') {
            throw new InvalidWebhookSignature('Missing Stripe signature or webhook secret.');
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }
        if (! $timestamp || ! $signatures || abs(now()->getTimestamp() - $timestamp) > self::TOLERANCE_SECONDS) {
            throw new InvalidWebhookSignature('Stripe signature missing or outside the allowed time window.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
        $valid = false;
        foreach ($signatures as $signature) {
            $valid = $valid || hash_equals($expected, $signature);
        }
        if (! $valid) {
            throw new InvalidWebhookSignature('Stripe signature mismatch.');
        }

        $event = json_decode($rawBody, true);
        $sessionId = $event['data']['object']['id'] ?? null;
        if (! is_array($event) || ! isset($event['id'], $event['type']) || ! $sessionId) {
            throw new InvalidWebhookSignature('Malformed Stripe event.');
        }

        // We only act on Checkout Session events; anything else is stored and ignored (unknown reference).
        return new WebhookNotification((string) $event['id'], (string) $event['type'], (string) $sessionId, $event);
    }

    public function fetchStatus(Payment $payment): GatewayStatus
    {
        $response = $this->client()->retry(2, 300, throw: false)
            ->get(self::API.'/checkout/sessions/'.urlencode((string) $payment->provider_reference));

        if (! $response->successful()) {
            throw new RuntimeException('Stripe status lookup failed: '.$response->status());
        }

        $session = $response->json();
        if (($session['payment_status'] ?? null) === 'paid') {
            $intent = $session['payment_intent'] ?? null;

            return new GatewayStatus(GatewayStatus::SUCCEEDED,
                Money::ofMinor((int) $session['amount_total'], strtoupper((string) $session['currency'])),
                providerPaymentId: is_array($intent) ? ($intent['id'] ?? null) : $intent);
        }
        if (($session['status'] ?? null) === 'expired') {
            return new GatewayStatus(GatewayStatus::CANCELLED, reason: 'checkout_expired');
        }

        return new GatewayStatus(GatewayStatus::PENDING);
    }

    public function refund(Payment $payment, Money $amount, string $idempotencyKey): RefundResult
    {
        if (! $payment->provider_payment_id) {
            return new RefundResult(RefundResult::FAILED, reason: 'missing_payment_intent');
        }

        $response = $this->client()->withHeaders(['Idempotency-Key' => $idempotencyKey])->post(self::API.'/refunds', [
            'payment_intent' => $payment->provider_payment_id,
            'amount' => $amount->minor,
            'metadata' => ['payment_id' => (string) $payment->id, 'key' => $idempotencyKey],
        ]);
        if (! $response->successful()) {
            return new RefundResult(RefundResult::FAILED, reason: mb_substr((string) ($response->json('error.code') ?? $response->status()), 0, 100));
        }

        return new RefundResult(match ($response->json('status')) {
            'succeeded' => RefundResult::SUCCEEDED,
            'failed', 'canceled' => RefundResult::FAILED,
            default => RefundResult::PENDING, // pending / requires_action: settled later by refunds:sync
        }, (string) $response->json('id'), $response->json('failure_reason'));
    }

    public function refundStatus(string $providerRefundId): RefundResult
    {
        $response = $this->client()->get(self::API.'/refunds/'.urlencode($providerRefundId));
        if (! $response->successful()) {
            throw new RuntimeException('Stripe refund lookup failed: '.$response->status());
        }

        return new RefundResult(match ($response->json('status')) {
            'succeeded' => RefundResult::SUCCEEDED,
            'failed', 'canceled' => RefundResult::FAILED,
            default => RefundResult::PENDING,
        }, $providerRefundId, $response->json('failure_reason'));
    }

    public function cancelCheckout(Payment $payment): void
    {
        if ($payment->provider_reference) {
            $this->client()->post(self::API.'/checkout/sessions/'.urlencode($payment->provider_reference).'/expire');
        }
    }

    private function client(): PendingRequest
    {
        $key = (string) ($this->config['secret_key'] ?? '');
        if ($key === '') {
            throw new RuntimeException('STRIPE_SECRET_KEY is not configured.');
        }

        return Http::withToken($key)->asForm()->acceptJson()->timeout(15)->connectTimeout(5);
    }

    private function returnUrl(string $template, Order $order): string
    {
        // {CHECKOUT_SESSION_ID} is filled in by Stripe; {ORDER} by us.
        return str_replace('{ORDER}', $order->reference, $template);
    }
}
