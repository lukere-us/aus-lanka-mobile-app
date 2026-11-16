<?php

namespace Tests\Feature;

use App\Modules\Currencies\Models\Currency;
use App\Modules\Identity\Models\Role;
use App\Modules\Orders\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsOrders;
use Tests\TestCase;

class CurrencyTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
    }

    private function setRate(string $code, string $rate, bool $active = true)
    {
        return $this->actingAs($this->userWithRole(Role::SUPER_ADMIN))
            ->putJson("/api/v1/admin/currencies/{$code}", ['rate_per_base' => $rate, 'is_active' => $active]);
    }

    public function test_aud_is_the_active_base_and_usd_lkr_wait_for_a_rate(): void
    {
        $this->getJson('/api/v1/currencies')->assertOk()
            ->assertJsonPath('meta.base_currency', 'AUD')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'AUD');

        $this->actingAs($this->userWithRole(Role::SUPER_ADMIN))->putJson('/api/v1/admin/currencies/USD', ['is_active' => true])
            ->assertUnprocessable()->assertJsonPath('error.code', 'rate_required');
    }

    public function test_admin_sets_rates_activates_currencies_and_it_is_audited(): void
    {
        $this->setRate('USD', '0.65')->assertOk()->assertJsonPath('data.rate_per_base', '0.65000000')->assertJsonPath('data.is_active', true);
        $this->setRate('LKR', '195.5')->assertOk();

        $this->getJson('/api/v1/currencies')->assertJsonCount(3, 'data');
        $this->assertDatabaseHas('audit_logs', ['action' => 'currency.updated']);
    }

    public function test_admin_can_add_a_new_currency(): void
    {
        $this->actingAs($this->userWithRole(Role::SUPER_ADMIN))
            ->putJson('/api/v1/admin/currencies/nzd', ['name' => 'New Zealand Dollar', 'symbol' => 'NZ$', 'rate_per_base' => '1.08', 'is_active' => true])
            ->assertOk()->assertJsonPath('data.code', 'NZD');
    }

    public function test_base_rate_cannot_change_and_only_settings_admins_manage_currencies(): void
    {
        $this->setRate('AUD', '2')->assertUnprocessable()->assertJsonPath('error.code', 'base_currency_locked');
        $this->actingAs($this->userWithRole(Role::FINANCE_MANAGER))->putJson('/api/v1/admin/currencies/USD', ['rate_per_base' => '0.7'])
            ->assertForbidden();
    }

    public function test_checkout_in_usd_converts_from_aud_and_locks_the_rate(): void
    {
        $this->setRate('USD', '0.65');

        $response = $this->actingAs($this->customer)->postJson('/api/v1/orders',
            ['items' => [['type' => 'test_item', 'id' => 1, 'quantity' => 2]], 'currency' => 'usd'],
            ['Idempotency-Key' => 'usd-checkout-01'],
        )->assertCreated();

        // AUD 1,500 x 2 = AUD 3,000 -> USD 975.00 each, USD 1,950.00 total at 0.65
        $response->assertJsonPath('data.total', ['amount' => '1950.00', 'currency' => 'USD'])
            ->assertJsonPath('data.items.0.unit_price', ['amount' => '975.00', 'currency' => 'USD'])
            ->assertJsonPath('data.exchange_rate.base_total', ['amount' => '3000.00', 'currency' => 'AUD'])
            ->assertJsonPath('data.exchange_rate.rate', '0.65000000');

        $order = Order::firstOrFail();
        $this->assertSame('1950.00', $order->latestPayment->amount);
        $this->assertSame('USD', $order->latestPayment->currency);

        // A later rate change never alters an existing order.
        $this->setRate('USD', '0.70');
        $this->assertSame('1950.00', $order->fresh()->total);
    }

    public function test_inactive_or_unknown_currency_is_rejected(): void
    {
        $this->actingAs($this->customer)->postJson('/api/v1/orders',
            ['items' => [['type' => 'test_item', 'id' => 1, 'quantity' => 1]], 'currency' => 'LKR'],
            ['Idempotency-Key' => 'lkr-checkout-01'],
        )->assertUnprocessable()->assertJsonPath('error.code', 'currency_not_supported');
        $this->assertSame(0, Order::count());
        $this->assertFalse(Currency::where('code', 'LKR')->value('is_active'));
    }
}
