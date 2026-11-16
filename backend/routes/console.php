<?php

use Illuminate\Support\Facades\Schedule;

// Housekeeping (B05). Run the scheduler with `php artisan schedule:work` locally or a cron entry in production.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('auth:clear-resets')->everyFifteenMinutes();

// Release unpaid orders (and, from F05, their reserved tickets) once the hold time passes.
Schedule::command('orders:expire-pending')->everyMinute()->withoutOverlapping();
Schedule::command('rentals:expire')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('refunds:sync')->everyTenMinutes()->withoutOverlapping();
