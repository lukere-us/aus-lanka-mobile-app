<?php

namespace App\Modules\Payments\Data;

final class RefundResult
{
    public const SUCCEEDED = 'succeeded';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    public function __construct(public readonly string $status, public readonly ?string $providerRefundId = null, public readonly ?string $reason = null) {}
}
