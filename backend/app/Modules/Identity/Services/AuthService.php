<?php

namespace App\Modules\Identity\Services;

use App\Http\Responses\ApiException;
use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array{name: string, email: string, phone?: ?string, password: string} $data */
    public function registerCustomer(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'status' => User::STATUS_ACTIVE,
            ]);
            $user->roles()->attach(Role::where('slug', Role::CUSTOMER)->firstOrFail());

            return $user;
        });
    }

    public function attemptLogin(string $email, string $password): User
    {
        $user = User::where('email', $email)->first();

        // Same response whether the email exists or not, to avoid account enumeration.
        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['email' => ['These credentials do not match our records.']]);
        }

        if (! $user->isActive()) {
            throw new ApiException(403, 'account_suspended', 'This account is suspended. Please contact support.');
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }

    /** @return array{token: string, token_type: string, expires_at: string} */
    public function issueToken(User $user, string $deviceName): array
    {
        $expiresAt = now()->addDays(config('marketplace.token_ttl_days'));
        $token = $user->createToken($deviceName, ['*'], $expiresAt);

        return [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601ZuluString(),
        ];
    }

    public function revokeAllTokens(User $user, string $reason): void
    {
        $count = $user->tokens()->delete();
        $this->audit->record('user.tokens_revoked', $user, ['reason' => $reason, 'count' => $count]);
    }
}
