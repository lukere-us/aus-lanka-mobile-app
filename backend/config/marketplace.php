<?php

return [
    // Commercial currency for prices and orders (ISO 4217). Money is stored as DECIMAL(12,2) + currency code.
    'currency' => env('MARKETPLACE_CURRENCY', 'LKR'),

    // Timestamps are stored in UTC (config app.timezone); this zone is for customer-facing display and reports.
    'display_timezone' => env('MARKETPLACE_DISPLAY_TIMEZONE', 'Asia/Colombo'),

    // Where password-reset and other links send people (customer web or app deep-link handler).
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    // Lifetime of API bearer tokens issued at login.
    'token_ttl_days' => (int) env('API_TOKEN_TTL_DAYS', 30),

    'super_admin' => [
        'email' => env('SUPER_ADMIN_EMAIL'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],
];
