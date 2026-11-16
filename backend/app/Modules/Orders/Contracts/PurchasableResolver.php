<?php

namespace App\Modules\Orders\Contracts;

use App\Models\User;
use App\Modules\Orders\Support\ResolvedItem;

/**
 * Each sellable module (rental packages, ticket types, passes) registers one resolver with the
 * PurchasableRegistry. It turns a client's {type, id, quantity} into a priced snapshot, or throws a
 * ValidationException / ApiException when the item cannot be bought.
 */
interface PurchasableResolver
{
    public function resolve(int|string $id, int $quantity, User $buyer): ResolvedItem;
}
