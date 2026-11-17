<?php

return [
    // "log" writes pushes to storage/logs; "fcm" sends through Firebase Cloud Messaging (needs FCM_CREDENTIALS).
    'push_driver' => env('PUSH_DRIVER', 'log'),
    'fcm' => ['credentials' => env('FCM_CREDENTIALS', storage_path('app/firebase-service-account.json'))],

    // Reminders
    'rental_expiry_warning_hours' => 3,
    'event_reminder_hours' => 24,
];
