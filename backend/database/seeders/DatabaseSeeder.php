<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Identity\Models\Role;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $email = config('marketplace.super_admin.email');
        $password = config('marketplace.super_admin.password');

        if ($email && $password) {
            $admin = User::firstOrCreate(
                ['email' => $email],
                ['name' => 'Super Admin', 'password' => $password, 'status' => User::STATUS_ACTIVE],
            );
            $admin->forceFill(['email_verified_at' => $admin->email_verified_at ?? now()])->save();
            $admin->roles()->syncWithoutDetaching(Role::where('slug', Role::SUPER_ADMIN)->pluck('id'));
        }
    }
}
