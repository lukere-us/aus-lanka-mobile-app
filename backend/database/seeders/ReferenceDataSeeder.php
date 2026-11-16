<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Idempotent reference data, safe on every deploy: roles/permissions and currencies. */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RolesAndPermissionsSeeder::class, CurrencySeeder::class]);
    }
}
