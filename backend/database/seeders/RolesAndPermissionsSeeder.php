<?php

namespace Database\Seeders;

use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use Illuminate\Database\Seeder;

/** Idempotent: safe to run on every deploy to sync config/permissions.php into the database. */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissionIds = [];
        foreach (config('permissions.permissions') as $slug => $description) {
            $permissionIds[$slug] = Permission::updateOrCreate(['slug' => $slug], ['description' => $description])->id;
        }

        foreach (config('permissions.roles') as $slug => [$name, $permissions]) {
            $role = Role::updateOrCreate(['slug' => $slug], ['name' => $name]);
            $role->permissions()->sync(array_map(fn ($p) => $permissionIds[$p], $permissions));
        }
    }
}
