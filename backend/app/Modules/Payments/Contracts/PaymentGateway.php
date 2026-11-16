<?php

namespace App\Modules\Payments\Contracts;

use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Data\CheckoutSession;
use App\Modules\Payments\Data\GatewayStatus;
use App\Modules\Payments\Data\WebhookNotification;
use App\Modules\Payments\Exceptions\InvalidWebhookSignature;
use App\Modules\Payments\Models\Payment;

/**
 * One adapter per provider (Fake now; PayHere / WEBXPAY once chosen). Rule (plan 2.1): an order is
 * fulfilled only from fetchStatus(), a server-side call; return URLs and webhook bodies are hints.
 */
interface PaymentGateway
{
    public function name(): string;

    /** Start a hosted checkout for this payment; returns where to send the customer. */
    public function createCheckout(Order $order, Payment $payment): CheckoutSession;

    /**
     * Authenticate and parse an incoming notification.
     *
     * @param  array<string, string>  $headers  lower-cased header names
     *
     * @throws InvalidWebhookSignature
     */
    public function parseWebhook(string $rawBody, array $headers): WebhookNotification;

    /** Ask the provider, server to server, what really happened to this payment. */
    public function fetchStatus(Payment $payment): GatewayStatus;

    /** Close the provider's checkout page (our order expired first). Best effort. */
    public function cancelCheckout(Payment $payment): void;
}
