<?php

namespace App\Modules\Rentals\Console;

use App\Models\User;
use App\Modules\Identity\Models\Role;
use App\Modules\Movies\Models\RentalPackage;
use App\Modules\Orders\Services\CheckoutService;
use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Payments\Services\PaymentGatewayManager;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Rentals\Models\Rental;
use App\Modules\Rentals\Services\PlaybackService;
use App\Modules\Rentals\Services\RentalIssuer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Local test helper: runs the real flow (checkout -> fake payment -> server-side verification -> rental ->
 * entitlement check -> signed link) for a demo customer and prints a browser link that plays the sample film.
 */
class DemoRentalLink extends Command
{
    protected $signature = 'demo:rental-link {--email=demo.customer@example.com} {--package=}';

    protected $description = 'Rent the sample movie as a demo customer and print a link that plays it in the browser (local only).';

    public function handle(CheckoutService $checkout, PaymentGatewayManager $gateways, PaymentService $payments, RentalIssuer $rentals, PlaybackService $playback): int
    {
        if ($this->laravel->isProduction() || config('payments.default') !== 'fake' || config('video.provider') !== 'sample') {
            $this->error('Only for local testing with PAYMENT_GATEWAY=fake and VIDEO_PROVIDER=sample.');

            return self::FAILURE;
        }

        $package = $this->option('package') ? RentalPackage::find($this->option('package')) : RentalPackage::where('status', 'active')->first();
        if (! $package) {
            $this->error('No rental package found. Run: php artisan db:seed --class=Database\\Seeders\\DemoContentSeeder');

            return self::FAILURE;
        }

        $user = User::firstOrCreate(['email' => $this->option('email')], ['name' => 'Demo Customer', 'password' => Str::random(32), 'status' => User::STATUS_ACTIVE]);
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
        $user->roles()->syncWithoutDetaching(Role::where('slug', Role::CUSTOMER)->pluck('id'));

        $rental = Rental::where('user_id', $user->id)->where('movie_id', $package->movie_id)->whereIn('status', [Rental::READY, Rental::ACTIVE])->latest('id')->first();
        if (! $rental || ! in_array($rental->effectiveStatus(), [Rental::READY, Rental::ACTIVE], true)) {
            [$order] = $checkout->checkout($user, [['type' => 'rental_package', 'id' => $package->id, 'quantity' => 1]], 'demo-'.Str::random(16));
            /** @var FakeGateway $fake */
            $fake = $gateways->driver('fake');
            $fake->simulate($order->latestPayment->provider_reference, 'succeeded');
            $payments->verify($order->latestPayment, 'demo');     // same server-side check a webhook triggers
            $rentals->issueForOrder($order->id);                 // normally done by the queued OrderPaid listener
            $rental = Rental::where('order_id', $order->id)->firstOrFail();
            $this->line("Paid order {$order->reference} ({$order->total} {$order->currency}).");
        }

        $play = $playback->authorize($user, $rental->id);
        $this->info("Rental #{$rental->id} for '{$package->movie->title}' is active until {$play['rental_expires_at']}.");
        $this->line('Open this in your browser while `php artisan serve` is running (link valid '.config('video.link_ttl_minutes').' minutes):');
        $this->line(url('/api/v1/dev/player?src='.urlencode($play['manifest_url'])));

        return self::SUCCESS;
    }
}
