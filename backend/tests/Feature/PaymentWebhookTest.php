<?php

namespace Tests\Feature;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\BuildsOrders;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
    }

    public function test_verified_success_marks_order_paid_and_fires_fulfillment_once(): void
    {
        Event::fake([OrderPaid::class]);
        $order = $this->placeOrder();

        $this->providerSends($order, 'succeeded')->assertOk()->assertJsonPath('data.status', 'accepted');

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(Payment::SUCCEEDED, $order->latestPayment->status);
        Event::assertDispatchedTimes(OrderPaid::class, 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.paid', 'auditable_id' => $order->id]);
    }

    public function test_redelivered_and_repeated_webhooks_never_fulfil_twice(): void
    {
        Event::fake([OrderPaid::class]);
        $order = $this->placeOrder();

        $this->providerSends($order, 'succeeded', eventId: 'evt_same_1')->assertJsonPath('data.status', 'accepted');
        $this->providerSends($order, 'succeeded', eventId: 'evt_same_1')->assertJsonPath('data.status', 'duplicate');
        $this->providerSends($order, 'succeeded', eventId: 'evt_other_2')->assertOk(); // provider retries with a new id

        Event::assertDispatchedTimes(OrderPaid::class, 1);
        $this->assertSame(2, PaymentEvent::count());
        $this->assertSame('already_final', PaymentEvent::where('event_id', 'evt_other_2')->value('outcome'));
    }

    public function test_forged_signature_is_rejected_and_changes_nothing(): void
    {
        $order = $this->placeOrder();

        $this->providerSends($order, 'succeeded', tamper: true)
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_signature');

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);
        $this->assertSame(0, PaymentEvent::count());
    }

    public function test_webhook_body_is_not_trusted_without_server_side_confirmation(): void
    {
        $order = $this->placeOrder();
        $ref = $order->latestPayment->provider_reference;
        // A correctly signed "succeeded" notification, but the provider's own status lookup says pending.
        $hook = $this->fakeGateway()->simulate($ref, 'succeeded');
        $this->fakeGateway()->simulate($ref, 'pending');

        $this->call('POST', '/api/v1/payments/webhooks/fake', [], [], [],
            $this->transformHeadersToServerVars(['X-Fake-Signature' => $hook['headers']['x-fake-signature']]), $hook['body'])->assertOk();

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);
    }

    public function test_amount_mismatch_is_held_for_review_and_not_fulfilled(): void
    {
        Event::fake([OrderPaid::class]);
        $order = $this->placeOrder(); // total AUD 3000.00

        $this->providerSends($order, 'succeeded', amount: '30.00')->assertOk();

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);
        $this->assertSame(Payment::REVIEW, $order->latestPayment->fresh()->status);
        Event::assertNotDispatched(OrderPaid::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.amount_mismatch']);
    }

    public function test_failed_payment_marks_order_failed(): void
    {
        $order = $this->placeOrder();

        $this->providerSends($order, 'failed')->assertOk();

        $this->assertSame(OrderStatus::Failed, $order->fresh()->status);
        $this->assertSame(Payment::FAILED, $order->latestPayment->fresh()->status);
    }

    public function test_app_return_triggers_a_server_side_check_not_a_client_claim(): void
    {
        $order = $this->placeOrder();
        $url = "/api/v1/orders/{$order->reference}/verify-payment";

        $this->actingAs($this->customer)->postJson($url)->assertOk()->assertJsonPath('data.status', 'pending_payment');

        $this->fakeGateway()->simulate($order->latestPayment->provider_reference, 'succeeded'); // paid, webhook delayed
        $this->actingAs($this->customer)->postJson($url)->assertOk()->assertJsonPath('data.status', 'paid');
    }

    public function test_unknown_provider_and_unknown_payment_reference_are_handled(): void
    {
        $this->postJson('/api/v1/payments/webhooks/nopay', [])->assertNotFound();

        $hook = $this->fakeGateway()->simulate('fake_does_not_exist', 'succeeded', '10.00');
        $this->call('POST', '/api/v1/payments/webhooks/fake', [], [], [],
            $this->transformHeadersToServerVars(['X-Fake-Signature' => $hook['headers']['x-fake-signature']]), $hook['body'])->assertOk();
        $this->assertSame('unknown_payment', PaymentEvent::first()->outcome);
    }

    public function test_dev_fake_checkout_page_completes_a_payment(): void
    {
        $order = $this->placeOrder();
        $checkoutUrl = parse_url($order->latestPayment->checkout_url, PHP_URL_PATH);

        $this->getJson($checkoutUrl)->assertOk()->assertJsonPath('data.amount.amount', '3000.00');
        $this->getJson($checkoutUrl.'?outcome=succeeded')->assertOk()->assertJsonPath('data.payment_status', 'succeeded');

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }
}
