<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Orders\Contracts\PurchasableResolver;
use App\Modules\Orders\Support\ResolvedItem;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/** Stand-in for rental packages / ticket types until those modules exist. Price = LKR 1,500.00 (id 99 = sold out). */
class TestItemResolver implements PurchasableResolver
{
    public function resolve(int|string $id, int $quantity, User $buyer): ResolvedItem
    {
        if ((int) $id === 99) {
            throw ValidationException::withMessages(['items' => ['Sold out.']]);
        }

        return new ResolvedItem("Test item #{$id}", Money::of('1500.00', 'LKR'), $quantity, metadata: ['test_id' => (int) $id]);
    }
}
