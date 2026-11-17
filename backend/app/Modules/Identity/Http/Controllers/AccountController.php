<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Currencies\Models\Currency;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\DeviceToken;
use App\Modules\Identity\Services\AccountService;
use App\Modules\Identity\Services\VerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** The signed-in customer's own account (C02, C04). */
class AccountController extends Controller
{
    public function __construct(private readonly AccountService $accounts, private readonly VerificationService $verification) {}

    public function update(Request $request): UserResource
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+[1-9]\d{7,14}$/', Rule::unique('users', 'phone')->ignore($user->id)],
            'preferred_currency' => ['sometimes', 'nullable', Rule::in(Currency::where('is_active', true)->pluck('code')->all())],
            'marketing_opt_in' => ['sometimes', 'boolean'],
            'notification_prefs' => ['sometimes', 'array'],
            'notification_prefs.push' => ['sometimes', 'boolean'],
            'notification_prefs.email_reminders' => ['sometimes', 'boolean'],
        ], ['phone.regex' => 'Enter the mobile number in international format, e.g. +61412345678.']);

        return new UserResource($this->accounts->updateProfile($user, $data)->fresh('roles'));
    }

    public function changeEmail(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['required', 'string'],
        ]);
        $code = $this->accounts->changeEmail($user, $data['password'], mb_strtolower(trim($data['email'])));

        return response()->json(['data' => ['message' => 'Email updated. We sent a code to verify it.'] + ($code ? ['debug_code' => $code] : [])]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);
        $revoked = $this->accounts->changePassword($request->user(), $data['current_password'], $data['password']);

        return response()->json(['data' => ['message' => 'Password changed.', 'other_sessions_signed_out' => $revoked]]);
    }

    public function sendCode(Request $request, string $channel): JsonResponse
    {
        $code = $this->verification->send($request->user(), $channel);

        return response()->json(['data' => ['message' => $channel === 'email' ? 'We emailed you a 6-digit code.' : 'We texted you a 6-digit code.',
            'expires_in_minutes' => VerificationService::TTL_MINUTES] + ($code ? ['debug_code' => $code] : [])], 202);
    }

    public function verifyCode(Request $request, string $channel): UserResource
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $this->verification->verify($request->user(), $channel, $data['code']);

        return new UserResource($request->user()->fresh()->load('roles'));
    }

    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'in:ios,android,web'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);
        $device = $this->accounts->registerDevice($request->user(), $data['token'], $data['platform'], $data['device_name'] ?? null);

        return response()->json(['data' => ['id' => $device->id, 'platform' => $device->platform, 'device_name' => $device->device_name]], 201);
    }

    public function removeDevice(Request $request): Response
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);
        DeviceToken::where('user_id', $request->user()->id)->where('token', $data['token'])->delete();

        return response()->noContent();
    }

    public function sessions(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken()?->id;

        return response()->json(['data' => $request->user()->tokens()->latest('last_used_at')->get()->map(fn ($t) => [
            'id' => $t->id, 'device' => $t->name, 'current' => $t->id === $current,
            'last_used_at' => $t->last_used_at?->toIso8601ZuluString(), 'created_at' => $t->created_at?->toIso8601ZuluString(),
        ])]);
    }

    public function revokeSession(Request $request, int $session): Response
    {
        $request->user()->tokens()->whereKey($session)->delete();

        return response()->noContent();
    }

    public function export(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->accounts->export($request->user())]);
    }

    public function destroy(Request $request): Response
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
            'forfeit_active_purchases' => ['sometimes', 'boolean'],
        ]);
        $this->accounts->delete($request->user(), $data['password'], $data['reason'] ?? null, (bool) ($data['forfeit_active_purchases'] ?? false));

        return response()->noContent();
    }
}
