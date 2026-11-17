<?php

use App\Http\Controllers\HealthController;
use App\Modules\Admin\Http\Controllers\UserManagementController;
use App\Modules\Currencies\Http\Controllers\CurrencyController;
use App\Modules\EventOps\Http\Controllers\OrganizerOpsController;
use App\Modules\EventOps\Http\Controllers\ScannerController;
use App\Modules\Events\Http\Controllers\AdminEventController;
use App\Modules\Events\Http\Controllers\OrganizerEventController;
use App\Modules\Events\Http\Controllers\PublicEventController;
use App\Modules\Finance\Http\Controllers\FinanceController;
use App\Modules\Identity\Http\Controllers\AccountController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
use App\Modules\Identity\Http\Middleware\EnsureEmailVerified;
use App\Modules\Movies\Http\Controllers\AdminMovieController;
use App\Modules\Movies\Http\Controllers\PublicMovieController;
use App\Modules\Notifications\Http\Controllers\InboxController;
use App\Modules\Orders\Http\Controllers\AdminOrderController;
use App\Modules\Orders\Http\Controllers\OrderController;
use App\Modules\Organizers\Http\Controllers\AdminOrganizerController;
use App\Modules\Organizers\Http\Controllers\OrganizerController;
use App\Modules\Payments\Http\Controllers\FakeGatewayController;
use App\Modules\Payments\Http\Controllers\WebhookController;
use App\Modules\Refunds\Http\Controllers\RefundController;
use App\Modules\Rentals\Http\Controllers\DevStreamController;
use App\Modules\Rentals\Http\Controllers\RentalController;
use App\Modules\Reports\Http\Controllers\ReportsController;
use App\Modules\Settings\Http\Controllers\SettingsController;
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

    // --- My account (C02, C04) -------------------------------------------------------------
    Route::prefix('me')->name('me.')->group(function () {
        Route::patch('/', [AccountController::class, 'update'])->name('update');
        Route::delete('/', [AccountController::class, 'destroy'])->middleware('throttle:auth')->name('destroy');
        Route::put('email', [AccountController::class, 'changeEmail'])->middleware('throttle:auth')->name('email');
        Route::put('password', [AccountController::class, 'changePassword'])->middleware('throttle:auth')->name('password');
        Route::post('verify/{channel}/send', [AccountController::class, 'sendCode'])->whereIn('channel', ['email', 'sms'])->middleware('throttle:verification')->name('verify.send');
        Route::post('verify/{channel}', [AccountController::class, 'verifyCode'])->whereIn('channel', ['email', 'sms'])->middleware('throttle:verification')->name('verify');
        Route::post('devices', [AccountController::class, 'registerDevice'])->name('devices.store');
        Route::delete('devices', [AccountController::class, 'removeDevice'])->name('devices.destroy');
        Route::get('sessions', [AccountController::class, 'sessions'])->name('sessions');
        Route::delete('sessions/{session}', [AccountController::class, 'revokeSession'])->whereNumber('session')->name('sessions.destroy');
        Route::get('export', [AccountController::class, 'export'])->name('export');
        Route::get('notifications', [InboxController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read', [InboxController::class, 'markRead'])->name('notifications.read');
    });

    // --- Orders and checkout (D01, D05) -------------------------------------------
    Route::prefix('orders')->name('orders.')->group(function () {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::post('/', [OrderController::class, 'store'])->middleware(['permission:customer.purchase', EnsureEmailVerified::class])->name('store');
        Route::get('{reference}', [OrderController::class, 'show'])->name('show');
        Route::post('{reference}/verify-payment', [OrderController::class, 'verifyPayment'])->name('verify-payment');
        Route::post('{reference}/pay', [OrderController::class, 'retryPayment'])->middleware(['permission:customer.purchase', EnsureEmailVerified::class])->name('pay');
        Route::get('{reference}/refunds', [RefundController::class, 'customerIndex'])->name('refunds.index');
        Route::post('{reference}/refunds', [RefundController::class, 'customerRequest'])->name('refunds.store');
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
    Route::middleware('permission:scanner.checkin|events.manage')->prefix('scanner')->name('scanner.')->group(function () {
        Route::get('events', [ScannerController::class, 'events'])->name('events');
        Route::get('events/{event}/stats', [ScannerController::class, 'stats'])->whereNumber('event')->name('events.stats');
        Route::post('devices', [ScannerController::class, 'registerDevice'])->name('devices.store');
    });
    Route::post('scanner/check-in', CheckInController::class)->middleware(['permission:scanner.checkin|events.manage', 'throttle:scanner'])->name('scanner.check-in');

    // --- Organizer portal (C05, F01, F03) ------------------------------------------------
    Route::post('organizer/apply', [OrganizerController::class, 'apply'])->middleware('permission:organizer.apply')->name('organizer.apply');
    Route::prefix('organizer')->name('organizer.')->group(function () {
        Route::get('profile', [OrganizerController::class, 'show'])->name('profile');
        Route::get('balance', [FinanceController::class, 'myBalance'])->name('balance');
        Route::get('ledger', [FinanceController::class, 'myLedger'])->name('ledger');
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

            // Event day operations (F09, G01, G04)
            Route::whereNumber(['event', 'gate', 'user', 'ticket'])->prefix('events/{event}')->name('events.')->group(function () {
                Route::get('gates', [OrganizerOpsController::class, 'gates'])->name('gates.index');
                Route::post('gates', [OrganizerOpsController::class, 'addGate'])->name('gates.store');
                Route::delete('gates/{gate}', [OrganizerOpsController::class, 'removeGate'])->name('gates.destroy');
                Route::get('staff', [OrganizerOpsController::class, 'staff'])->name('staff.index');
                Route::post('staff', [OrganizerOpsController::class, 'assignStaff'])->name('staff.store');
                Route::delete('staff/{user}', [OrganizerOpsController::class, 'unassignStaff'])->name('staff.destroy');
                Route::get('attendees', [OrganizerOpsController::class, 'attendees'])->middleware('permission:organizer.attendees.view')->name('attendees');
                Route::get('attendees.csv', [OrganizerOpsController::class, 'attendeesCsv'])->middleware('permission:organizer.attendees.view')->name('attendees.csv');
                Route::get('sales', [OrganizerOpsController::class, 'sales'])->name('sales');
                Route::get('stats', [OrganizerOpsController::class, 'stats'])->name('stats');
                Route::get('scans', [OrganizerOpsController::class, 'scans'])->name('scans');
                Route::post('tickets/{ticket}/admit', [OrganizerOpsController::class, 'admit'])->name('tickets.admit');
                Route::post('tickets/{ticket}/undo-check-in', [OrganizerOpsController::class, 'undo'])->name('tickets.undo');
            });
            Route::get('devices', [OrganizerOpsController::class, 'devices'])->name('devices.index');
            Route::post('devices/{device}/revoke', [OrganizerOpsController::class, 'revokeDevice'])->whereNumber('device')->name('devices.revoke');
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

        // --- Dashboard, reports, audit log (I02, I08) ---------------------------------------
        Route::middleware('permission:reports.view')->group(function () {
            Route::get('dashboard', [ReportsController::class, 'dashboard'])->name('dashboard');
            Route::prefix('reports')->name('reports.')->group(function () {
                Route::get('orders.csv', [ReportsController::class, 'orders'])->name('orders');
                Route::get('refunds.csv', [ReportsController::class, 'refunds'])->name('refunds');
                Route::get('payouts.csv', [ReportsController::class, 'payouts'])->name('payouts');
                Route::get('organizer-balances.csv', [ReportsController::class, 'organizerBalances'])->name('organizer-balances');
                Route::get('ticket-sales.csv', [ReportsController::class, 'ticketSales'])->name('ticket-sales');
                Route::get('rentals.csv', [ReportsController::class, 'rentals'])->name('rentals');
            });
        });
        Route::get('audit-logs', [ReportsController::class, 'auditLogs'])->middleware('permission:audit.view')->name('audit-logs');

        Route::get('settings', [SettingsController::class, 'index'])->middleware('permission:settings.manage')->name('settings.index');
        Route::put('settings', [SettingsController::class, 'update'])->middleware('permission:settings.manage')->name('settings.update');

        Route::middleware('permission:refunds.approve')->group(function () {
            Route::get('refunds', [RefundController::class, 'index'])->name('refunds.index');
            Route::post('refunds/{refund}/approve', [RefundController::class, 'approve'])->name('refunds.approve');
            Route::post('refunds/{refund}/reject', [RefundController::class, 'reject'])->name('refunds.reject');
            Route::post('orders/{reference}/refunds', [RefundController::class, 'staffRefund'])->name('orders.refunds.store');
        });
        Route::post('events/{event}/cancel', [RefundController::class, 'cancelEvent'])->middleware('permission:events.manage')->name('events.cancel');
        Route::post('tickets/{ticket}/cancel', [RefundController::class, 'cancelTicket'])->middleware('permission:events.manage')->name('tickets.cancel');

        Route::middleware('permission:payouts.manage')->group(function () {
            Route::get('payouts', [FinanceController::class, 'payouts'])->name('payouts.index');
            Route::get('organizers/{organizer}/balance', [FinanceController::class, 'balance'])->name('organizers.balance');
            Route::get('organizers/{organizer}/ledger', [FinanceController::class, 'ledger'])->name('organizers.ledger');
            Route::post('organizers/{organizer}/payouts', [FinanceController::class, 'recordPayout'])->name('organizers.payouts.store');
            Route::put('organizers/{organizer}/commission', [FinanceController::class, 'setCommission'])->name('organizers.commission');
        });

        Route::get('organizers', [AdminOrganizerController::class, 'index'])->middleware('permission:organizers.approve')->name('organizers.index');
        Route::patch('organizers/{organizer}/status', [AdminOrganizerController::class, 'updateStatus'])->middleware('permission:organizers.approve')->name('organizers.status');

        Route::get('events', [AdminEventController::class, 'index'])->middleware('permission:events.approve|events.manage')->name('events.index');
        Route::post('events/{event}/review', [AdminEventController::class, 'review'])->middleware('permission:events.approve')->name('events.review');
    });
});
