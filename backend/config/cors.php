<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Request-Id', 'Accept-Language'],
    'exposed_headers' => ['X-Request-Id', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'],
    'max_age' => 3600,
    'supports_credentials' => false,
];
