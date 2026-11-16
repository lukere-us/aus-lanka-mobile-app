<?php

namespace App\Modules\Refunds\Jobs;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Refunds\Services\RefundService;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Full refunds (including booking fees) for every valid ticket of a cancelled event. Safe to re-run. */
class RefundCancelledEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public readonly int $eventId, public readonly int $staffId, public readonly string $reason)
    {
        $this->onQueue('payments');
    }

    public function handle(RefundService $refunds): void
    {
        $staff = User::findOrFail($this->staffId);
        $orderIds = Ticket::where('event_id', $this->eventId)->where('status', Ticket::VALID)->distinct()->pluck('order_id');

        foreach (Order::whereIn('id', $orderIds)->with('succeededPayment')->get() as $order) {
            $ticketIds = Ticket::where('order_id', $order->id)->where('event_id', $this->eventId)->where('status', Ticket::VALID)->pluck('id')->all();
            try {
                $refunds->refundByStaff($staff, $order, $ticketIds, [], "Event cancelled: {$this->reason}", includeFees: true);
            } catch (Throwable $e) {
                // One bad order must not stop the rest; finance sees it in the log and refund list.
                Log::error('Cancelled-event refund failed', ['order' => $order->reference, 'error' => $e->getMessage()]);
            }
        }
    }
}
