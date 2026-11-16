<?php

namespace App\Modules\Rentals\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Movies\Models\RentalPackage;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Rentals\Models\Rental;
use Illuminate\Support\Facades\DB;

/** Payment-to-rental fulfillment (E04). One rental per paid order item, however often it runs. */
class RentalIssuer
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function issueForOrder(int $orderId): int
    {
        return DB::transaction(function () use ($orderId) {
            $order = Order::whereKey($orderId)->lockForUpdate()->with('items')->firstOrFail();
            if ($order->status !== OrderStatus::Paid) {
                return 0;
            }

            $created = 0;
            foreach ($order->items->where('item_type', 'rental_package') as $item) {
                if (Rental::where('order_item_id', $item->id)->exists()) {
                    continue;
                }
                $package = RentalPackage::findOrFail($item->purchasable_id);
                $rental = Rental::create([
                    'user_id' => $order->user_id,
                    'movie_id' => $package->movie_id,
                    'rental_package_id' => $package->id,
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'status' => Rental::READY,
                    'watch_hours' => $package->watch_hours,
                    'activate_by' => ($order->paid_at ?? now())->copy()->addDays($package->start_within_days),
                ]);
                $this->audit->record('rental.created', $rental, ['order' => $order->reference]);
                $created++;
            }

            return $created;
        });
    }
}
