<?php

namespace App\Modules\Payments\Data;

final class WebhookNotification
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $eventId,
        public readonly string $type,
        public readonly string $providerReference,
        public readonly array $payload,
    ) {}
}
