<?php

return [
    // Base currency (ISO 4217): all prices are set in it; payouts and reports use it. Customers may pay in any
    // active currency from the currencies table at an admin-set rate. Money = DECIMAL(12,2) + currency code.
    'currency' => env('MARKETPLACE_CURRENCY', 'AUD'),

    // Timestamps are stored in UTC. Events show in their venue's timezone; this zone is for reports and admin screens.
    'display_timezone' => env('MARKETPLACE_DISPLAY_TIMEZONE', 'Australia/Sydney'),

    // Where password-reset and other links send people (customer web or app deep-link handler).
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    // Lifetime of API bearer tokens issued at login.
    'token_ttl_days' => (int) env('API_TOKEN_TTL_DAYS', 30),

    'super_admin' => [
        'email' => env('SUPER_ADMIN_EMAIL'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],
];
