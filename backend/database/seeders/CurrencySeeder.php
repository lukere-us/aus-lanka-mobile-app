<?php

namespace Database\Seeders;

use App\Modules\Currencies\Models\Currency;
use Illuminate\Database\Seeder;

/**
 * Base currency AUD (rate 1). USD and LKR are created inactive with no rate: an admin sets today's rate
 * and switches them on (PUT /api/v1/admin/currencies/{code}). Existing rates are never overwritten.
 */
class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $base = config('marketplace.currency');
        Currency::query()->where('code', '!=', $base)->update(['is_base' => false]);
        Currency::updateOrCreate(['code' => $base], ['name' => 'Australian Dollar', 'symbol' => 'A$', 'decimals' => 2,
            'rate_per_base' => '1', 'is_base' => true, 'is_active' => true]);

        foreach ([['USD', 'US Dollar', 'US$'], ['LKR', 'Sri Lankan Rupee', 'Rs']] as [$code, $name, $symbol]) {
            Currency::firstOrCreate(['code' => $code], ['name' => $name, 'symbol' => $symbol, 'decimals' => 2, 'is_active' => false]);
        }
    }
}
