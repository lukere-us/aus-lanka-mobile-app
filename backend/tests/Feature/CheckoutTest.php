<?php

namespace Tests\Feature;

use App\Modules\Identity\Models\Role;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsOrders;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
    }

    public function test_checkout_creates_pending_order_with_item_snapshot_and_payment_link(): void
    {
        $response = $this->checkout([['type' => 'test_item', 'id' => 7, 'quantity' => 3]]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.total', ['amount' => '4500.00', 'currency' => 'AUD'])
            ->assertJsonPath('data.items.0.name', 'Test item #7')
            ->assertJsonPath('data.items.0.unit_price.amount', '1500.00')
            ->assertJsonPath('data.items.0.quantity', 3)
            ->assertJsonPath('data.payment.status', 'initiated')
            ->assertJsonPath('data.payment.provider', 'fake');

        $this->assertMatchesRegularExpression('/^EM-[0-9A-HJKMNP-TV-Z]{10}$/', $response->json('data.reference'));
        $this->assertStringContainsString('/api/v1/dev/fake-gateway/fake_', $response->json('data.payment.checkout_url'));
        $this->assertNotNull($response->json('data.expires_at'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.created']);
    }

    public function test_same_idempotency_key_returns_the_same_order_instead_of_a_second_one(): void
    {
        $first = $this->checkout(key: 'retry-key-0001')->assertCreated();
        $second = $this->checkout(key: 'retry-key-0001')->assertOk();

        $this->assertSame($first->json('data.reference'), $second->json('data.reference'));
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
    }

    public function test_reusing_a_key_for_different_items_is_rejected(): void
    {
        $this->checkout(key: 'retry-key-0002')->assertCreated();

        $this->checkout([['type' => 'test_item', 'id' => 2, 'quantity' => 1]], 'retry-key-0002')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_conflict');
    }

    public function test_idempotency_key_is_required(): void
    {
        $this->actingAs($this->customer)
            ->postJson('/api/v1/orders', ['items' => [['type' => 'test_item', 'id' => 1, 'quantity' => 1]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key'], 'error.details');
    }

    public function test_unknown_or_unavailable_items_are_rejected(): void
    {
        $this->checkout([['type' => 'spaceship', 'id' => 1, 'quantity' => 1]])
            ->assertUnprocessable()->assertJsonValidationErrors(['items.0.type'], 'error.details');

        $this->checkout([['type' => 'test_item', 'id' => 99, 'quantity' => 1]])->assertUnprocessable();
        $this->assertSame(0, Order::count());
    }

    public function test_staff_roles_without_purchase_permission_cannot_check_out(): void
    {
        $this->checkout(as: $this->userWithRole(Role::GATE_STAFF))->assertForbidden();
    }

    public function test_customers_see_only_their_own_orders(): void
    {
        $mine = $this->placeOrder();
        $other = $this->userWithRole(Role::CUSTOMER);

        $this->actingAs($other)->getJson("/api/v1/orders/{$mine->reference}")->assertNotFound();
        $this->actingAs($other)->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->customer)->getJson('/api/v1/orders')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_finance_can_search_orders_but_customers_cannot_use_admin_api(): void
    {
        $order = $this->placeOrder();

        $this->actingAs($this->customer)->getJson('/api/v1/admin/orders')->assertForbidden();
        $this->actingAs($this->userWithRole(Role::FINANCE_MANAGER))
            ->getJson('/api/v1/admin/orders?status=pending_payment')
            ->assertOk()
            ->assertJsonPath('data.0.reference', $order->reference)
            ->assertJsonPath('data.0.customer.email', $this->customer->email);
    }

    public function test_unpaid_orders_expire_after_the_hold_time_but_paid_ones_do_not(): void
    {
        $unpaid = $this->placeOrder();
        $paid = $this->placeOrder();
        $this->providerSends($paid, 'succeeded')->assertOk();

        $this->travel(config('payments.order_ttl_minutes') + 1)->minutes();
        $this->artisan('orders:expire-pending')->assertSuccessful();

        $this->assertSame(OrderStatus::Expired, $unpaid->fresh()->status);
        $this->assertSame(OrderStatus::Paid, $paid->fresh()->status);
    }

    public function test_expiry_checks_the_provider_first_and_keeps_a_late_payment(): void
    {
        $order = $this->placeOrder();
        // Customer paid at the provider, but the webhook never arrived.
        $this->fakeGateway()->simulate($order->latestPayment->provider_reference, 'succeeded');

        $this->travel(config('payments.order_ttl_minutes') + 1)->minutes();
        $this->artisan('orders:expire-pending')->assertSuccessful();

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    public function test_failed_payment_can_be_retried_with_a_new_checkout(): void
    {
        $order = $this->placeOrder();
        $this->providerSends($order, 'failed')->assertOk();
        $this->assertSame(OrderStatus::Failed, $order->fresh()->status);

        $this->actingAs($this->customer)->postJson("/api/v1/orders/{$order->reference}/pay")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.payment.status', 'initiated');
        $this->assertSame(2, $order->payments()->count());
    }
}
