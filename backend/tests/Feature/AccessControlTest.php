<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public static function rolesWithoutUserAccess(): array
    {
        return [[Role::CUSTOMER], [Role::ORGANIZER], [Role::GATE_STAFF], [Role::FINANCE_MANAGER], [Role::CONTENT_MANAGER]];
    }

    #[DataProvider('rolesWithoutUserAccess')]
    public function test_roles_without_permission_cannot_list_users(string $role): void
    {
        $this->actingAs($this->userWithRole($role))
            ->getJson('/api/v1/admin/users')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_administrator_can_list_and_filter_users(): void
    {
        $admin = $this->userWithRole(Role::ADMINISTRATOR);
        $this->userWithRole(Role::ORGANIZER, ['name' => 'Galle Events']);
        $this->userWithRole(Role::CUSTOMER);

        $this->actingAs($admin)->getJson('/api/v1/admin/users?role=organizer')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Galle Events')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_administrator_can_assign_roles_and_it_is_audited(): void
    {
        $admin = $this->userWithRole(Role::ADMINISTRATOR);
        $user = $this->userWithRole(Role::CUSTOMER);

        $this->actingAs($admin)->putJson("/api/v1/admin/users/{$user->id}/roles", ['roles' => ['customer', 'organizer']])
            ->assertOk()
            ->assertJsonPath('data.roles', fn ($roles) => collect($roles)->sort()->values()->all() === ['customer', 'organizer']);

        $this->assertTrue($user->fresh()->can('organizer.events.manage'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.roles_changed', 'actor_id' => $admin->id, 'auditable_id' => $user->id]);
    }

    public function test_only_super_admin_can_grant_super_admin(): void
    {
        $admin = $this->userWithRole(Role::ADMINISTRATOR);
        $super = $this->userWithRole(Role::SUPER_ADMIN);
        $user = $this->userWithRole(Role::CUSTOMER);

        $this->actingAs($admin)->putJson("/api/v1/admin/users/{$user->id}/roles", ['roles' => ['super_admin']])
            ->assertUnprocessable();

        $this->actingAs($super)->putJson("/api/v1/admin/users/{$user->id}/roles", ['roles' => ['super_admin']])
            ->assertOk();
    }

    public function test_finance_manager_cannot_assign_roles(): void
    {
        $user = $this->userWithRole(Role::CUSTOMER);

        $this->actingAs($this->userWithRole(Role::FINANCE_MANAGER))
            ->putJson("/api/v1/admin/users/{$user->id}/roles", ['roles' => ['administrator']])
            ->assertForbidden();
    }

    public function test_suspending_a_user_revokes_their_tokens_and_blocks_access(): void
    {
        $admin = $this->userWithRole(Role::ADMINISTRATOR);
        $customer = $this->userWithRole(Role::CUSTOMER);
        $token = $customer->createToken('phone')->plainTextToken;

        $this->actingAs($admin)->patchJson("/api/v1/admin/users/{$customer->id}/status", ['status' => 'suspended', 'reason' => 'Chargeback fraud'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');
        $this->resetAuth();

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.status_changed', 'auditable_id' => $customer->id]);
    }

    public function test_suspension_requires_a_reason_and_admins_cannot_suspend_themselves(): void
    {
        $admin = $this->userWithRole(Role::ADMINISTRATOR);
        $customer = $this->userWithRole(Role::CUSTOMER);

        $this->actingAs($admin)->patchJson("/api/v1/admin/users/{$customer->id}/status", ['status' => 'suspended'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason'], 'error.details');

        $this->actingAs($admin)->patchJson("/api/v1/admin/users/{$admin->id}/status", ['status' => 'suspended', 'reason' => 'x'])
            ->assertStatus(409)->assertJsonPath('error.code', 'cannot_change_own_status');
    }

    public function test_super_admin_passes_every_permission_and_suspended_users_pass_none(): void
    {
        $super = $this->userWithRole(Role::SUPER_ADMIN);
        $this->assertTrue($super->can('payouts.manage'));
        $this->assertTrue($super->can('any.future.permission'));

        $suspendedAdmin = User::factory()->suspended()->withRole(Role::ADMINISTRATOR)->create();
        $this->assertFalse($suspendedAdmin->can('users.view'));
    }

    public function test_every_role_in_config_is_seeded_with_its_permissions(): void
    {
        foreach (config('permissions.roles') as $slug => [, $permissions]) {
            $role = Role::where('slug', $slug)->with('permissions')->first();
            $this->assertNotNull($role, "Role $slug missing");
            $this->assertEqualsCanonicalizing($permissions, $role->permissions->pluck('slug')->all());
        }
    }
}
