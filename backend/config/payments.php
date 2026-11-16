<?php

use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Payments\Gateways\StripeGateway;

return [
    // Gateway used for new checkouts: "stripe" in staging/production, "fake" for local work without keys.
    'default' => env('PAYMENT_GATEWAY', 'fake'),

    // Minutes a pending order (and any reserved stock) is held before it expires.
    'order_ttl_minutes' => (int) env('ORDER_TTL_MINUTES', 15),

    'gateways' => [
        'fake' => [
            'class' => FakeGateway::class,
            'webhook_secret' => env('FAKE_GATEWAY_SECRET', 'local-fake-gateway-secret'),
        ],
        'stripe' => [
            'class' => StripeGateway::class,
            'secret_key' => env('STRIPE_SECRET_KEY'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            // Where Stripe sends the customer afterwards. {ORDER} = our reference, {CHECKOUT_SESSION_ID} = Stripe's.
            // The app then calls POST /orders/{reference}/verify-payment; the return itself proves nothing.
            'success_url' => env('STRIPE_SUCCESS_URL', env('FRONTEND_URL', 'http://localhost:3000').'/checkout/return?order={ORDER}&session_id={CHECKOUT_SESSION_ID}'),
            'cancel_url' => env('STRIPE_CANCEL_URL', env('FRONTEND_URL', 'http://localhost:3000').'/checkout/return?order={ORDER}&cancelled=1'),
        ],
    ],
];
