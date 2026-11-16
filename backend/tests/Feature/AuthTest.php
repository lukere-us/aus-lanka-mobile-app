<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Models\Role;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nimali Perera',
            'email' => 'Nimali@Example.com',
            'phone' => '+94771234567',
            'password' => 'Secure-Pass123',
            'password_confirmation' => 'Secure-Pass123',
            'device_name' => 'Pixel 8',
        ], $overrides);
    }

    public function test_customer_can_register_and_gets_token_and_customer_role(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->registerPayload());

        $response->assertCreated()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', 'nimali@example.com')
            ->assertJsonPath('data.user.roles', ['customer'])
            ->assertJsonPath('data.user.permissions', fn ($p) => collect($p)->sort()->values()->all() === ['customer.purchase', 'organizer.apply']);
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertTrue(User::where('email', 'nimali@example.com')->first()->hasRole(Role::CUSTOMER));
    }

    public function test_registration_validates_input_with_error_envelope(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/auth/register', $this->registerPayload([
            'email' => 'taken@example.com', 'password' => 'weak', 'password_confirmation' => 'weak', 'phone' => '0771234567',
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonValidationErrors(['email', 'password', 'phone'], 'error.details');
    }

    public function test_user_can_login_and_read_profile(): void
    {
        $user = $this->userWithRole(Role::CUSTOMER, ['email' => 'kamal@example.com']);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'KAMAL@example.com', 'password' => 'Password123', 'device_name' => 'iPhone',
        ])->assertOk()->json('data.token');

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.roles', ['customer']);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_wrong_password_is_rejected_without_revealing_account(): void
    {
        $this->userWithRole(Role::CUSTOMER, ['email' => 'kamal@example.com']);

        $wrong = $this->postJson('/api/v1/auth/login', ['email' => 'kamal@example.com', 'password' => 'nope', 'device_name' => 'x']);
        $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'ghost@example.com', 'password' => 'nope', 'device_name' => 'x']);

        $wrong->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        $this->assertSame($wrong->json('error.details'), $unknown->json('error.details'));
    }

    public function test_suspended_user_cannot_login(): void
    {
        User::factory()->suspended()->create(['email' => 'sus@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'sus@example.com', 'password' => 'Password123', 'device_name' => 'x'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'account_suspended');
    }

    public function test_protected_routes_require_a_token(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = $this->userWithRole(Role::CUSTOMER);
        $token = $user->createToken('phone')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->resetAuth();

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = $this->userWithRole(Role::CUSTOMER);
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123', 'device_name' => 'x'])
            ->json('data.token');
        $this->resetAuth();

        $this->travel(config('marketplace.token_ttl_days') + 1)->days();

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_forgot_password_gives_same_answer_and_sends_link_only_to_real_account(): void
    {
        Notification::fake();
        $user = $this->userWithRole(Role::CUSTOMER);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(202);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@example.com'])->assertStatus(202);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            return str_contains($n->toMail($user)->actionUrl, '/reset-password?token=');
        });
        Notification::assertCount(1);
    }

    public function test_password_reset_changes_password_and_revokes_tokens(): void
    {
        $user = $this->userWithRole(Role::CUSTOMER);
        $user->createToken('old-phone');
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'Brand-New-Pass1', 'password_confirmation' => 'Brand-New-Pass1',
        ])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Brand-New-Pass1', 'device_name' => 'x'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.tokens_revoked', 'auditable_id' => $user->id]);
    }

    public function test_password_reset_with_invalid_token_fails(): void
    {
        $user = $this->userWithRole(Role::CUSTOMER);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'bogus', 'email' => $user->email,
            'password' => 'Brand-New-Pass1', 'password_confirmation' => 'Brand-New-Pass1',
        ])->assertUnprocessable()->assertJsonValidationErrors(['token'], 'error.details');
    }

    public function test_login_is_rate_limited(): void
    {
        $payload = ['email' => 'brute@example.com', 'password' => 'nope', 'device_name' => 'x'];
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', $payload)->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', $payload)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'too_many_requests')
            ->assertHeader('Retry-After');
    }
}
