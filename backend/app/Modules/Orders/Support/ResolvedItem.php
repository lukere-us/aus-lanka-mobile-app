<?php

namespace App\Modules\Orders\Support;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

final class ResolvedItem
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly string $name,
        public readonly Money $unitPrice,
        public readonly int $quantity,
        public readonly ?Model $purchasable = null,
        public readonly array $metadata = [],
    ) {}

    public function lineTotal(): Money
    {
        return $this->unitPrice->multiply($this->quantity);
    }
}
