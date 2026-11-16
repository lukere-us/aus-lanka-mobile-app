<?php

use App\Http\Controllers\HealthController;
use App\Modules\Admin\Http\Controllers\UserManagementController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
use App\Modules\Orders\Http\Controllers\AdminOrderController;
use App\Modules\Orders\Http\Controllers\OrderController;
use App\Modules\Payments\Http\Controllers\FakeGatewayController;
use App\Modules\Payments\Http\Controllers\WebhookController;
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

// --- Payment provider notifications (D03/D04): signature-checked, no user auth ----------
Route::post('payments/webhooks/{provider}', WebhookController::class)
    ->middleware('throttle:webhooks')->where('provider', '[a-z0-9_]+')->name('payments.webhook');

// Local stand-in for the provider's hosted checkout page; never registered in production.
if (! app()->isProduction() && config('payments.default') === 'fake') {
    Route::get('dev/fake-gateway/{reference}', FakeGatewayController::class)->name('dev.fake-gateway');
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

    // --- Admin: users, roles, status (C03, I05 API) --------------------------------
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('users', [UserManagementController::class, 'index'])->middleware('permission:users.view')->name('users.index');
        Route::get('users/{user}', [UserManagementController::class, 'show'])->middleware('permission:users.view')->name('users.show');
        Route::put('users/{user}/roles', [UserManagementController::class, 'updateRoles'])->middleware('permission:roles.assign')->name('users.roles');
        Route::patch('users/{user}/status', [UserManagementController::class, 'updateStatus'])->middleware('permission:users.manage')->name('users.status');

        Route::get('orders', [AdminOrderController::class, 'index'])->middleware('permission:orders.view')->name('orders.index');
        Route::get('orders/{reference}', [AdminOrderController::class, 'show'])->middleware('permission:orders.view')->name('orders.show');
    });
});
