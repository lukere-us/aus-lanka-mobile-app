<?php

namespace App\Modules\Payments\Gateways;

use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\CheckoutSession;
use App\Modules\Payments\Data\GatewayStatus;
use App\Modules\Payments\Data\WebhookNotification;
use App\Modules\Payments\Exceptions\InvalidWebhookSignature;
use App\Modules\Payments\Models\Payment;
use App\Support\Money;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/**
 * Development/test stand-in for a Sri Lankan gateway. Behaves like a real one: hosted checkout URL,
 * HMAC-signed webhooks, and a server-side status lookup that is the source of truth.
 * The "provider side" state lives in the cache under fakepay:{reference}.
 */
class FakeGateway implements PaymentGateway
{
    public const SIGNATURE_HEADER = 'x-fake-signature';

    public function __construct(private readonly Cache $cache, private readonly string $secret) {}

    public function name(): string
    {
        return 'fake';
    }

    public function createCheckout(Order $order, Payment $payment): CheckoutSession
    {
        $reference = 'fake_'.Str::random(24);
        $this->cache->put($this->key($reference), [
            'status' => GatewayStatus::PENDING,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
        ], now()->addDay());

        return new CheckoutSession(url("/api/v1/dev/fake-gateway/{$reference}"), $reference);
    }

    public function parseWebhook(string $rawBody, array $headers): WebhookNotification
    {
        $given = (string) ($headers[self::SIGNATURE_HEADER] ?? '');
        if ($given === '' || ! hash_equals($this->sign($rawBody), $given)) {
            throw new InvalidWebhookSignature('Fake gateway signature mismatch.');
        }

        $data = json_decode($rawBody, true);
        if (! is_array($data) || ! isset($data['id'], $data['type'], $data['reference'])) {
            throw new InvalidWebhookSignature('Malformed fake gateway notification.');
        }

        return new WebhookNotification((string) $data['id'], (string) $data['type'], (string) $data['reference'], $data);
    }

    public function fetchStatus(Payment $payment): GatewayStatus
    {
        $state = $this->cache->get($this->key((string) $payment->provider_reference));
        if (! $state) {
            return new GatewayStatus(GatewayStatus::PENDING);
        }

        return new GatewayStatus($state['status'], Money::of($state['amount'], $state['currency']), $state['reason'] ?? null);
    }

    public function cancelCheckout(Payment $payment): void
    {
        $key = $this->key((string) $payment->provider_reference);
        if (($state = $this->cache->get($key)) && $state['status'] === GatewayStatus::PENDING) {
            $state['status'] = GatewayStatus::CANCELLED;
            $this->cache->put($key, $state, now()->addDay());
        }
    }

    /**
     * Simulate the customer finishing checkout at the provider. Returns the signed webhook the provider
     * would send: ['body' => string, 'headers' => array].
     *
     * @return array{body: string, headers: array<string, string>}
     */
    public function simulate(string $reference, string $status, ?string $amount = null, ?string $eventId = null): array
    {
        $state = $this->cache->get($this->key($reference)) ?? ['amount' => $amount ?? '0.00', 'currency' => 'AUD'];
        $state['status'] = $status;
        if ($amount !== null) {
            $state['amount'] = $amount;
        }
        $this->cache->put($this->key($reference), $state, now()->addDay());

        $body = json_encode([
            'id' => $eventId ?? 'evt_'.Str::random(20),
            'type' => 'payment.'.$status,
            'reference' => $reference,
            'amount' => $state['amount'],
            'currency' => $state['currency'],
        ], JSON_THROW_ON_ERROR);

        return ['body' => $body, 'headers' => [self::SIGNATURE_HEADER => $this->sign($body)]];
    }

    public function sign(string $body): string
    {
        return hash_hmac('sha256', $body, $this->secret);
    }

    private function key(string $reference): string
    {
        return "fakepay:{$reference}";
    }
}
