<?php

namespace App\Modules\Tickets\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Events\Models\TicketType;
use App\Modules\Events\Services\InventoryService;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Support\Facades\DB;

/** Payment-to-ticket fulfillment (F06). Idempotent: running it twice for one order issues nothing new. */
class TicketIssuer
{
    public function __construct(private readonly InventoryService $inventory, private readonly AuditLogger $audit) {}

    /** @return int tickets issued by this call */
    public function issueForOrder(int $orderId): int
    {
        return DB::transaction(function () use ($orderId) {
            $order = Order::whereKey($orderId)->lockForUpdate()->with('items')->firstOrFail();
            if ($order->status !== OrderStatus::Paid) {
                return 0;
            }

            $issued = 0;
            foreach ($order->items->where('item_type', 'ticket_type') as $item) {
                if (Ticket::where('order_item_id', $item->id)->exists()) {
                    continue;
                }
                if (! $this->inventory->commitSale($item, (int) $item->purchasable_id, $item->quantity)) {
                    continue; // logged as tickets.unfulfillable; finance refunds it (D06)
                }

                $eventId = TicketType::whereKey($item->purchasable_id)->value('event_id');
                for ($seq = 1; $seq <= $item->quantity; $seq++) {
                    Ticket::create([
                        'event_id' => $eventId,
                        'ticket_type_id' => $item->purchasable_id,
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'user_id' => $order->user_id,
                        'sequence' => $seq,
                        'qr_token' => Ticket::newQrToken(),
                        'status' => Ticket::VALID,
                    ]);
                }
                $issued += $item->quantity;
            }

            if ($issued > 0) {
                $this->audit->record('tickets.issued', $order, ['count' => $issued]);
            }

            return $issued;
        });
    }
}
