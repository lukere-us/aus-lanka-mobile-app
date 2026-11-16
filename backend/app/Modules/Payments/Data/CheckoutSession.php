<?php

namespace App\Modules\Payments\Data;

final class CheckoutSession
{
    public function __construct(public readonly string $redirectUrl, public readonly string $providerReference) {}
}
