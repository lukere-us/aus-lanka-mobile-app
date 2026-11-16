<?php

use Illuminate\Support\Facades\Route;

/*
| All public API routes are versioned. Breaking changes go into a new version file (api_v2.php),
| never into an existing one. See docs/api-conventions.md.
*/

Route::prefix('v1')->name('v1.')->group(base_path('routes/api_v1.php'));
