<?php

use App\Modules\Payments\Gateways\FakeGateway;

return [
    // Gateway used for new checkouts. "fake" until PayHere / WEBXPAY is chosen (decision log).
    'default' => env('PAYMENT_GATEWAY', 'fake'),

    // Minutes a pending order (and any reserved stock) is held before it expires.
    'order_ttl_minutes' => (int) env('ORDER_TTL_MINUTES', 15),

    'gateways' => [
        'fake' => [
            'class' => FakeGateway::class,
            'webhook_secret' => env('FAKE_GATEWAY_SECRET', 'local-fake-gateway-secret'),
        ],
        // 'payhere' => ['class' => App\Modules\Payments\Gateways\PayHereGateway::class, 'merchant_id' => ..., 'merchant_secret' => ...],
    ],
];
