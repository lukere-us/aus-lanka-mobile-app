<?php

namespace Tests;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Seed roles/permissions after RefreshDatabase migrates. */
    protected bool $seed = true;

    protected string $seeder = RolesAndPermissionsSeeder::class;

    protected function userWithRole(string $role, array $attributes = []): User
    {
        return User::factory()->withRole($role)->create($attributes);
    }

    /** Make the next request authenticate from scratch (the guard caches the user per test). */
    protected function resetAuth(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
