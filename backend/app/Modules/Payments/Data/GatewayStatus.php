<?php

namespace App\Modules\Payments\Data;

use App\Support\Money;

final class GatewayStatus
{
    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const PENDING = 'pending';

    public function __construct(
        public readonly string $status,
        public readonly ?Money $amount = null,
        public readonly ?string $reason = null,
        public readonly ?string $providerPaymentId = null,
    ) {}

    public function isFinal(): bool
    {
        return $this->status !== self::PENDING;
    }
}
