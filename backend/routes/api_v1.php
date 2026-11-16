<?php

use App\Http\Controllers\HealthController;
use App\Modules\Admin\Http\Controllers\UserManagementController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
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

Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [AuthController::class, 'me'])->name('me');

    // --- Admin: users, roles, status (C03, I05 API) --------------------------------
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('users', [UserManagementController::class, 'index'])->middleware('permission:users.view')->name('users.index');
        Route::get('users/{user}', [UserManagementController::class, 'show'])->middleware('permission:users.view')->name('users.show');
        Route::put('users/{user}/roles', [UserManagementController::class, 'updateRoles'])->middleware('permission:roles.assign')->name('users.roles');
        Route::patch('users/{user}/status', [UserManagementController::class, 'updateStatus'])->middleware('permission:users.manage')->name('users.status');
    });
});
