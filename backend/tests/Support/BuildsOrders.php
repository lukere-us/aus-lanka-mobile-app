<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Identity\Models\Role;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\PurchasableRegistry;
use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Payments\Services\PaymentGatewayManager;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

trait BuildsOrders
{
    protected User $customer;

    protected function setUpOrders(): void
    {
        $this->app->make(PurchasableRegistry::class)->register('test_item', new TestItemResolver);
        $this->customer = $this->userWithRole(Role::CUSTOMER);
    }

    protected function checkout(array $items = [['type' => 'test_item', 'id' => 1, 'quantity' => 2]], ?string $key = null, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->customer)
            ->postJson('/api/v1/orders', ['items' => $items], ['Idempotency-Key' => $key ?? 'key-'.Str::random(12)]);
    }

    protected function placeOrder(): Order
    {
        $reference = $this->checkout()->assertCreated()->json('data.reference');

        return Order::where('reference', $reference)->with('latestPayment')->firstOrFail();
    }

    protected function fakeGateway(): FakeGateway
    {
        return $this->app->make(PaymentGatewayManager::class)->driver('fake');
    }

    /** Provider marks the payment and sends its signed webhook. */
    protected function providerSends(Order $order, string $status, ?string $amount = null, ?string $eventId = null, bool $tamper = false): TestResponse
    {
        $hook = $this->fakeGateway()->simulate($order->latestPayment->provider_reference, $status, $amount, $eventId);
        $headers = ['X-Fake-Signature' => $tamper ? str_repeat('0', 64) : $hook['headers'][FakeGateway::SIGNATURE_HEADER], 'Content-Type' => 'application/json'];

        return $this->call('POST', '/api/v1/payments/webhooks/fake', [], [], [], $this->transformHeadersToServerVars($headers), $hook['body']);
    }
}
