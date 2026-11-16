<?php

namespace App\Modules\Events\Services;

use App\Http\Responses\ApiException;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Events\Models\TicketReservation;
use App\Modules\Events\Models\TicketType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use Illuminate\Support\Facades\DB;

/**
 * The only code that moves ticket counters (F04/F05). Every change happens inside a transaction with the
 * ticket_type row locked (SELECT ... FOR UPDATE), so two buyers can never take the last ticket twice.
 */
class InventoryService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Hold stock for an order item. Must run inside the checkout transaction. */
    public function reserve(Order $order, OrderItem $item, int $ticketTypeId, int $quantity): TicketReservation
    {
        /** @var TicketType $type */
        $type = TicketType::whereKey($ticketTypeId)->lockForUpdate()->firstOrFail();
        if ($type->available() < $quantity) {
            throw new ApiException(409, 'sold_out', "Only {$type->available()} {$type->name} ticket(s) left.",
                ['ticket_type_id' => $type->id, 'available' => $type->available()]);
        }

        $type->increment('quantity_reserved', $quantity);

        return TicketReservation::create([
            'ticket_type_id' => $type->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'quantity' => $quantity,
            'status' => TicketReservation::HELD,
            'expires_at' => $order->expires_at,
        ]);
    }

    /** Give held stock back (order expired/cancelled). Each reservation is released at most once. */
    public function releaseForOrder(int $orderId): int
    {
        return DB::transaction(function () use ($orderId) {
            $released = 0;
            $reservations = TicketReservation::where('order_id', $orderId)
                ->where('status', TicketReservation::HELD)->lockForUpdate()->get();

            foreach ($reservations as $reservation) {
                $type = TicketType::whereKey($reservation->ticket_type_id)->lockForUpdate()->firstOrFail();
                $type->decrement('quantity_reserved', min($reservation->quantity, $type->quantity_reserved));
                $reservation->update(['status' => TicketReservation::RELEASED]);
                $released += $reservation->quantity;
            }

            return $released;
        });
    }

    /**
     * Turn a paid order item's hold into a sale. If the hold was already released (payment arrived after
     * expiry) take from free stock; if none is left return false so the order is flagged for refund.
     * Must run inside the fulfillment transaction.
     */
    public function commitSale(OrderItem $item, int $ticketTypeId, int $quantity): bool
    {
        $type = TicketType::whereKey($ticketTypeId)->lockForUpdate()->firstOrFail();
        $reservation = TicketReservation::where('order_item_id', $item->id)->lockForUpdate()->first();

        if ($reservation?->status === TicketReservation::CONVERTED) {
            return true;
        }

        if ($reservation?->status === TicketReservation::HELD) {
            $type->quantity_reserved -= min($reservation->quantity, $type->quantity_reserved);
        } elseif ($type->available() < $quantity) {
            $this->audit->record('tickets.unfulfillable', $item, ['ticket_type_id' => $type->id, 'quantity' => $quantity]);

            return false;
        }

        $type->quantity_sold += $quantity;
        $type->save();
        $reservation?->update(['status' => TicketReservation::CONVERTED]);

        return true;
    }
}
