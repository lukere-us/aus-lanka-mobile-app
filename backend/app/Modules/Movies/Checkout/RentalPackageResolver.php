<?php

namespace App\Modules\Movies\Checkout;

use App\Http\Responses\ApiException;
use App\Models\User;
use App\Modules\Movies\Models\RentalPackage;
use App\Modules\Orders\Contracts\PurchasableResolver;
use App\Modules\Orders\Support\ResolvedItem;
use App\Modules\Rentals\Models\Rental;

/** Lets checkout sell movie rentals: {"type": "rental_package", "id": 3, "quantity": 1}. */
class RentalPackageResolver implements PurchasableResolver
{
    public function resolve(int|string $id, int $quantity, User $buyer): ResolvedItem
    {
        $package = RentalPackage::with('movie')->find($id);
        if (! $package || $package->status !== 'active' || ! $package->movie?->isRentable()) {
            throw new ApiException(422, 'not_available', 'This movie is not available to rent.');
        }
        if ($quantity !== 1) {
            throw new ApiException(422, 'invalid_quantity', 'A movie can be rented once per order.');
        }

        $current = Rental::where('user_id', $buyer->id)->where('movie_id', $package->movie_id)
            ->whereIn('status', [Rental::READY, Rental::ACTIVE])->get()
            ->first(fn (Rental $r) => in_array($r->effectiveStatus(), [Rental::READY, Rental::ACTIVE], true));
        if ($current) {
            throw new ApiException(409, 'already_rented', 'You already have this movie rented.', ['rental_id' => $current->id]);
        }

        return new ResolvedItem(
            name: "{$package->movie->title} — {$package->name}",
            unitPrice: $package->priceMoney(),
            quantity: 1,
            purchasable: $package,
            metadata: ['movie_id' => $package->movie_id, 'watch_hours' => $package->watch_hours, 'quality' => $package->quality],
        );
    }
}
