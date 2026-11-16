<?php

namespace App\Modules\Refunds\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiException;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\TicketType;
use App\Modules\Orders\Models\Order;
use App\Modules\Refunds\Jobs\RefundCancelledEvent;
use App\Modules\Refunds\Models\Refund;
use App\Modules\Refunds\Services\RefundService;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RefundController extends Controller
{
    public function __construct(private readonly RefundService $refunds, private readonly AuditLogger $audit) {}

    // --- Customer ---------------------------------------------------------------------------

    public function customerRequest(Request $request, string $reference): JsonResponse
    {
        $v = $this->validateLines($request);
        $order = Order::where('reference', $reference)->where('user_id', $request->user()->id)->firstOrFail();
        $refund = $this->refunds->request($request->user(), $order, $v['ticket_ids'] ?? [], $v['rental_ids'] ?? [], $v['reason']);

        return response()->json(['data' => $refund->load('order', 'items')->toApi()], 201);
    }

    public function customerIndex(Request $request, string $reference): JsonResponse
    {
        $order = Order::where('reference', $reference)->where('user_id', $request->user()->id)->firstOrFail();

        return response()->json(['data' => Refund::where('order_id', $order->id)->with('order', 'items')->latest('id')->get()->map->toApi()]);
    }

    // --- Finance / admin ------------------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate(['status' => ['nullable', 'in:requested,rejected,processing,succeeded,failed']]);
        $page = Refund::with('order', 'items')->when($v['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->latest('id')->paginate(25);

        return response()->json(['data' => collect($page->items())->map->toApi(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage()]]);
    }

    public function approve(Request $request, Refund $refund): JsonResponse
    {
        $v = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => $this->refunds->approve($refund, $request->user(), $v['note'] ?? null)->toApi()]);
    }

    public function reject(Request $request, Refund $refund): JsonResponse
    {
        $v = $request->validate(['note' => ['required', 'string', 'max:500']]);

        return response()->json(['data' => $this->refunds->reject($refund, $request->user(), $v['note'])->toApi()]);
    }

    public function staffRefund(Request $request, string $reference): JsonResponse
    {
        $v = $this->validateLines($request) + $request->validate(['include_fees' => ['sometimes', 'boolean']]);
        $order = Order::where('reference', $reference)->firstOrFail();
        $refund = $this->refunds->refundByStaff($request->user(), $order, $v['ticket_ids'] ?? [], $v['rental_ids'] ?? [], $v['reason'], (bool) ($v['include_fees'] ?? false));

        return response()->json(['data' => $refund->toApi()], 201);
    }

    /** Cancel an event: stop sales and refund every valid ticket in full (queued). */
    public function cancelEvent(Request $request, Event $event): JsonResponse
    {
        $v = $request->validate(['reason' => ['required', 'string', 'max:300']]);
        if (in_array($event->status, [EventStatus::Cancelled, EventStatus::Completed], true)) {
            throw new ApiException(409, 'invalid_status_change', "This event is already {$event->status->value}.");
        }

        DB::transaction(function () use ($event, $v) {
            $before = $event->status;
            $event->update(['status' => EventStatus::Cancelled, 'review_note' => $v['reason']]);
            TicketType::where('event_id', $event->id)->update(['status' => 'hidden']);
            $this->audit->record('event.cancelled', $event, ['before' => $before->value, 'reason' => $v['reason']]);
        });
        RefundCancelledEvent::dispatch($event->id, $request->user()->id, $v['reason']);

        return response()->json(['data' => ['status' => 'cancelled', 'refunds' => 'queued']], 202);
    }

    /** Void a ticket without moving money (fraud, duplicate, comp). Optionally put the place back on sale. */
    public function cancelTicket(Request $request, Ticket $ticket): JsonResponse
    {
        $v = $request->validate(['reason' => ['required', 'string', 'max:300'], 'release_stock' => ['sometimes', 'boolean']]);

        DB::transaction(function () use ($ticket, $v) {
            if (Ticket::whereKey($ticket->id)->where('status', Ticket::VALID)->update(['status' => Ticket::CANCELLED]) !== 1) {
                throw new ApiException(409, 'ticket_not_valid', "This ticket is {$ticket->fresh()->status}.");
            }
            if ($v['release_stock'] ?? false) {
                $type = TicketType::whereKey($ticket->ticket_type_id)->lockForUpdate()->first();
                if ($type && $type->quantity_sold > 0) {
                    $type->decrement('quantity_sold');
                }
            }
            $this->audit->record('ticket.cancelled', $ticket, $v);
        });

        return response()->json(['data' => ['id' => $ticket->id, 'status' => Ticket::CANCELLED]]);
    }

    private function validateLines(Request $request): array
    {
        return $request->validate([
            'ticket_ids' => ['sometimes', 'array', 'max:50'],
            'ticket_ids.*' => ['integer', 'distinct'],
            'rental_ids' => ['sometimes', 'array', 'max:10'],
            'rental_ids.*' => ['integer', 'distinct'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
    }
}
