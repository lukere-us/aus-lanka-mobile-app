<?php

namespace App\Modules\Rentals\Console;

use App\Modules\Rentals\Models\Rental;
use Illuminate\Console\Command;

class ExpireRentals extends Command
{
    protected $signature = 'rentals:expire';

    protected $description = 'Mark rentals whose viewing window or start deadline has passed as expired.';

    public function handle(): int
    {
        $active = Rental::where('status', Rental::ACTIVE)->where('expires_at', '<=', now())->update(['status' => Rental::EXPIRED]);
        $unstarted = Rental::where('status', Rental::READY)->where('activate_by', '<=', now())->update(['status' => Rental::EXPIRED]);
        $this->info('Expired '.($active + $unstarted).' rental(s).');

        return self::SUCCESS;
    }
}
