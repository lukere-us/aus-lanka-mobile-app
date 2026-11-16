<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Identity\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'password' => static::$password ??= 'Password123',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => User::STATUS_SUSPENDED]);
    }

    public function withRole(string $slug): static
    {
        return $this->afterCreating(fn (User $user) => $user->roles()->attach(Role::where('slug', $slug)->firstOrFail()));
    }
}
