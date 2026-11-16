<?php

namespace Tests\Feature;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\PaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsOrders;
use Tests\TestCase;

/** Stripe Checkout adapter against a faked Stripe API (no keys or network needed). */
class StripeGatewayTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    private const WHSEC = 'whsec_test_secret';

    private string $sessionStatus = 'open';

    private string $paymentStatus = 'unpaid';

    private int $amountTotal = 300000;

    private bool $stripeDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'payments.default' => 'stripe',
            'payments.gateways.stripe.secret_key' => 'sk_test_123',
            'payments.gateways.stripe.webhook_secret' => self::WHSEC,
        ]);
        $this->setUpOrders();

        Http::fake([
            'api.stripe.com/v1/checkout/sessions/*/expire' => Http::response(['id' => 'cs_test_1', 'status' => 'expired']),
            'api.stripe.com/v1/checkout/sessions/*' => fn () => Http::response([
                'id' => 'cs_test_1', 'status' => $this->sessionStatus, 'payment_status' => $this->paymentStatus,
                'amount_total' => $this->amountTotal, 'currency' => 'aud',
            ]),
            'api.stripe.com/v1/checkout/sessions' => fn () => $this->stripeDown
                ? Http::response(['error' => ['message' => 'down']], 500)
                : Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']),
        ]);
    }

    private function stripeEvent(string $type, string $id = 'evt_1', ?int $timestamp = null, ?string $secret = null)
    {
        $body = json_encode(['id' => $id, 'type' => $type, 'data' => ['object' => ['id' => 'cs_test_1', 'object' => 'checkout.session']]]);
        $t = $timestamp ?? time();
        $sig = hash_hmac('sha256', "{$t}.{$body}", $secret ?? self::WHSEC);

        return $this->call('POST', '/api/v1/payments/webhooks/stripe', [], [], [],
            $this->transformHeadersToServerVars(['Stripe-Signature' => "t={$t},v1={$sig}", 'Content-Type' => 'application/json']), $body);
    }

    public function test_checkout_creates_a_stripe_session_for_the_order_total(): void
    {
        $this->checkout()->assertCreated()
            ->assertJsonPath('data.payment.provider', 'stripe')
            ->assertJsonPath('data.payment.checkout_url', 'https://checkout.stripe.com/c/pay/cs_test_1');

        Http::assertSent(function (Request $request) {
            $order = Order::first();

            return $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
                && $request->hasHeader('Authorization', 'Bearer sk_test_123')
                && $request->hasHeader('Idempotency-Key')
                && $request['mode'] === 'payment'
                && $request['client_reference_id'] === $order->reference
                && $request['line_items'][0]['price_data']['currency'] === 'aud'
                && (int) $request['line_items'][0]['price_data']['unit_amount'] === 300000
                && $request['expires_at'] >= now()->addMinutes(30)->getTimestamp();
        });
    }

    public function test_paid_session_webhook_marks_order_paid_after_server_side_check(): void
    {
        $this->checkout()->assertCreated();
        $this->paymentStatus = 'paid';
        $this->sessionStatus = 'complete';

        $this->stripeEvent('checkout.session.completed')->assertOk()->assertJsonPath('data.status', 'accepted');

        $this->assertSame(OrderStatus::Paid, Order::first()->status);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/checkout/sessions/cs_test_1'));
    }

    public function test_completed_event_is_ignored_when_stripe_says_unpaid(): void
    {
        $this->checkout()->assertCreated();

        $this->stripeEvent('checkout.session.completed')->assertOk();

        $this->assertSame(OrderStatus::PendingPayment, Order::first()->status);
        $this->assertSame('pending', PaymentEvent::first()->outcome);
    }

    public function test_bad_or_stale_signatures_are_rejected(): void
    {
        $this->checkout()->assertCreated();

        $this->stripeEvent('checkout.session.completed', secret: 'whsec_wrong')->assertStatus(400)->assertJsonPath('error.code', 'invalid_signature');
        $this->stripeEvent('checkout.session.completed', timestamp: time() - 600)->assertStatus(400);
        $this->assertSame(0, PaymentEvent::count());
    }

    public function test_amount_mismatch_from_stripe_is_held_for_review(): void
    {
        $this->checkout()->assertCreated();
        $this->paymentStatus = 'paid';
        $this->amountTotal = 100;

        $this->stripeEvent('checkout.session.completed')->assertOk();

        $this->assertSame(OrderStatus::PendingPayment, Order::first()->status);
        $this->assertSame('review', Order::first()->latestPayment->status);
    }

    public function test_expired_order_closes_the_stripe_session(): void
    {
        $this->checkout()->assertCreated();

        $this->travel(config('payments.order_ttl_minutes') + 1)->minutes();
        $this->artisan('orders:expire-pending');

        $this->assertSame(OrderStatus::Expired, Order::first()->status);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/checkout/sessions/cs_test_1/expire'));
    }

    public function test_stripe_outage_at_checkout_keeps_the_order_and_returns_502(): void
    {
        $this->stripeDown = true;

        $this->checkout()->assertStatus(502)->assertJsonPath('error.code', 'payment_gateway_unavailable');
        $this->assertSame(1, Order::count());
    }
}
