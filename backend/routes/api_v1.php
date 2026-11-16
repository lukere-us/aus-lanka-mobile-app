<?php

use App\Http\Controllers\HealthController;
use App\Modules\Admin\Http\Controllers\UserManagementController;
use App\Modules\Currencies\Http\Controllers\CurrencyController;
use App\Modules\Events\Http\Controllers\AdminEventController;
use App\Modules\Events\Http\Controllers\OrganizerEventController;
use App\Modules\Events\Http\Controllers\PublicEventController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
use App\Modules\Movies\Http\Controllers\AdminMovieController;
use App\Modules\Movies\Http\Controllers\PublicMovieController;
use App\Modules\Orders\Http\Controllers\AdminOrderController;
use App\Modules\Orders\Http\Controllers\OrderController;
use App\Modules\Organizers\Http\Controllers\AdminOrganizerController;
use App\Modules\Organizers\Http\Controllers\OrganizerController;
use App\Modules\Payments\Http\Controllers\FakeGatewayController;
use App\Modules\Payments\Http\Controllers\WebhookController;
use App\Modules\Rentals\Http\Controllers\DevStreamController;
use App\Modules\Rentals\Http\Controllers\RentalController;
use App\Modules\Tickets\Http\Controllers\CheckInController;
use App\Modules\Tickets\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::get('health', HealthController::class)->name('health');

// --- Identity (C01) ---------------------------------------------------------------
Route::prefix('auth')->name('auth.')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->name('register');
        Route::post('login', [AuthController::class, 'login'])->name('login');
        Route::post('forgot-password', [PasswordResetController::class, 'forgot'])->name('password.forgot');
        Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('password.reset');
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    });
});

// --- Currencies customers can pay in (AUD base) ---------------------------------------
Route::get('currencies', [CurrencyController::class, 'index'])->name('currencies.index');

// --- Movie discovery (E02) ------------------------------------------------------------
Route::get('movies', [PublicMovieController::class, 'index'])->name('movies.index');
Route::get('movies/{slug}', [PublicMovieController::class, 'show'])->name('movies.show');

// --- Public event discovery (F02) -------------------------------------------------
Route::get('events', [PublicEventController::class, 'index'])->name('events.index');
Route::get('events/{slug}', [PublicEventController::class, 'show'])->name('events.show');

// --- Payment provider notifications (D03/D04): signature-checked, no user auth ----------
Route::post('payments/webhooks/{provider}', WebhookController::class)
    ->middleware('throttle:webhooks')->where('provider', '[a-z0-9_]+')->name('payments.webhook');

// Local stand-in for the provider's hosted checkout page; never registered in production.
if (! app()->isProduction() && config('payments.default') === 'fake') {
    Route::get('dev/fake-gateway/{reference}', FakeGatewayController::class)->name('dev.fake-gateway');
}
// Sample video provider: signed-link "CDN" + a browser test player; never registered in production.
if (! app()->isProduction() && config('video.provider') === 'sample') {
    Route::get('dev/stream/{token}', [DevStreamController::class, 'stream'])->where('token', '[A-Za-z0-9_\-.]+')->name('dev.stream');
    Route::get('dev/player', [DevStreamController::class, 'player'])->name('dev.player');
}

Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [AuthController::class, 'me'])->name('me');

    // --- Orders and checkout (D01, D05) -------------------------------------------
    Route::prefix('orders')->name('orders.')->group(function () {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::post('/', [OrderController::class, 'store'])->middleware('permission:customer.purchase')->name('store');
        Route::get('{reference}', [OrderController::class, 'show'])->name('show');
        Route::post('{reference}/verify-payment', [OrderController::class, 'verifyPayment'])->name('verify-payment');
        Route::post('{reference}/pay', [OrderController::class, 'retryPayment'])->middleware('permission:customer.purchase')->name('pay');
    });

    // --- Movie rentals and playback (E05, E07, E08) ----------------------------------------
    Route::get('rentals', [RentalController::class, 'index'])->name('rentals.index');
    Route::get('rentals/{rental}', [RentalController::class, 'show'])->whereNumber('rental')->name('rentals.show');
    Route::post('rentals/{rental}/play', [RentalController::class, 'play'])->whereNumber('rental')->middleware('throttle:playback')->name('rentals.play');
    Route::put('rentals/{rental}/progress', [RentalController::class, 'progress'])->whereNumber('rental')->middleware('throttle:playback')->name('rentals.progress');

    // --- Ticket wallet (F07) ------------------------------------------------------------
    Route::get('tickets', [WalletController::class, 'index'])->name('tickets.index');
    Route::get('tickets/{ticket}', [WalletController::class, 'show'])->name('tickets.show');

    // --- Gate scanner (G03) ---------------------------------------------------------------
    Route::post('scanner/check-in', CheckInController::class)->middleware(['permission:scanner.checkin|events.manage', 'throttle:scanner'])->name('scanner.check-in');

    // --- Organizer portal (C05, F01, F03) ------------------------------------------------
    Route::post('organizer/apply', [OrganizerController::class, 'apply'])->middleware('permission:organizer.apply')->name('organizer.apply');
    Route::prefix('organizer')->name('organizer.')->group(function () {
        Route::get('profile', [OrganizerController::class, 'show'])->name('profile');
        Route::middleware('permission:organizer.staff.manage')->group(function () {
            Route::post('staff', [OrganizerController::class, 'addStaff'])->name('staff.store');
            Route::delete('staff/{user}', [OrganizerController::class, 'removeStaff'])->name('staff.destroy');
        });
        Route::middleware('permission:organizer.events.manage')->group(function () {
            Route::get('venues', [OrganizerEventController::class, 'venues'])->name('venues.index');
            Route::post('venues', [OrganizerEventController::class, 'storeVenue'])->name('venues.store');
            Route::get('events', [OrganizerEventController::class, 'index'])->name('events.index');
            Route::post('events', [OrganizerEventController::class, 'store'])->name('events.store');
            Route::get('events/{event}', [OrganizerEventController::class, 'show'])->whereNumber('event')->name('events.show');
            Route::patch('events/{event}', [OrganizerEventController::class, 'update'])->whereNumber('event')->name('events.update');
            Route::post('events/{event}/submit', [OrganizerEventController::class, 'submit'])->whereNumber('event')->name('events.submit');
            Route::post('events/{event}/ticket-types', [OrganizerEventController::class, 'storeTicketType'])->whereNumber('event')->name('ticket-types.store');
            Route::patch('events/{event}/ticket-types/{ticketType}', [OrganizerEventController::class, 'updateTicketType'])->whereNumber(['event', 'ticketType'])->name('ticket-types.update');
        });
    });

    // --- Admin: users, roles, status (C03, I05 API) --------------------------------
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('users', [UserManagementController::class, 'index'])->middleware('permission:users.view')->name('users.index');
        Route::get('users/{user}', [UserManagementController::class, 'show'])->middleware('permission:users.view')->name('users.show');
        Route::put('users/{user}/roles', [UserManagementController::class, 'updateRoles'])->middleware('permission:roles.assign')->name('users.roles');
        Route::patch('users/{user}/status', [UserManagementController::class, 'updateStatus'])->middleware('permission:users.manage')->name('users.status');

        Route::get('orders', [AdminOrderController::class, 'index'])->middleware('permission:orders.view')->name('orders.index');
        Route::get('orders/{reference}', [AdminOrderController::class, 'show'])->middleware('permission:orders.view')->name('orders.show');

        Route::get('currencies', [CurrencyController::class, 'adminIndex'])->middleware('permission:settings.manage')->name('currencies.index');
        Route::put('currencies/{code}', [CurrencyController::class, 'upsert'])->middleware('permission:settings.manage')->where('code', '[A-Za-z]{3}')->name('currencies.upsert');

        Route::middleware('permission:movies.manage')->prefix('movies')->name('movies.')->group(function () {
            Route::get('/', [AdminMovieController::class, 'index'])->name('index');
            Route::post('/', [AdminMovieController::class, 'store'])->name('store');
            Route::patch('{movie}', [AdminMovieController::class, 'update'])->name('update');
            Route::post('{movie}/assets', [AdminMovieController::class, 'addAsset'])->name('assets.store');
            Route::post('{movie}/packages', [AdminMovieController::class, 'addPackage'])->name('packages.store');
            Route::patch('{movie}/packages/{package}', [AdminMovieController::class, 'updatePackage'])->name('packages.update');
        });

        Route::get('organizers', [AdminOrganizerController::class, 'index'])->middleware('permission:organizers.approve')->name('organizers.index');
        Route::patch('organizers/{organizer}/status', [AdminOrganizerController::class, 'updateStatus'])->middleware('permission:organizers.approve')->name('organizers.status');

        Route::get('events', [AdminEventController::class, 'index'])->middleware('permission:events.approve|events.manage')->name('events.index');
        Route::post('events/{event}/review', [AdminEventController::class, 'review'])->middleware('permission:events.approve')->name('events.review');
    });
});
