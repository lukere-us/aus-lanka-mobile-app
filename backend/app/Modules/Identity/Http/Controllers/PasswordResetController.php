<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Http\Requests\ForgotPasswordRequest;
use App\Modules\Identity\Http\Requests\ResetPasswordRequest;
use App\Modules\Identity\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        // Always the same answer so the endpoint cannot be used to discover accounts.
        Password::sendResetLink($request->only('email'));

        return response()->json([
            'data' => ['message' => 'If an account exists for that email, a reset link has been sent.'],
        ], 202);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                $this->auth->revokeAllTokens($user, 'password_reset');
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => ['This password reset link is invalid or has expired.']]);
        }

        return response()->json(['data' => ['message' => 'Your password has been reset. Please sign in again.']]);
    }
}
