<?php

use Illuminate\Support\Facades\Schedule;

// Housekeeping (B05). Run the scheduler with `php artisan schedule:work` locally or a cron entry in production.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('auth:clear-resets')->everyFifteenMinutes();
