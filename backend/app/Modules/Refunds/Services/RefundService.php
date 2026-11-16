<?php

namespace App\Modules\Refunds\Services;

use App\Http\Responses\ApiException;
use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\TicketType;
use App\Modules\Finance\Services\LedgerService;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Data\RefundResult;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentGatewayManager;
use App\Modules\Refunds\Models\Refund;
use App\Modules\Refunds\Models\RefundItem;
use App\Modules\Rentals\Models\Rental;
use App\Modules\Settings\Services\PlatformSettings;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Refunds (D06) and their effects (F08). Lifecycle: requested -> (rejected) | processing -> succeeded | failed.
 * While processing, tickets are "refund_pending" (unscannable) and rentals revoked; a failed provider refund
 * puts them back. Money is returned only through the original payment, recorded exactly once.
 */
class RefundService
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly LedgerService $ledger,
        private readonly PlatformSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Customer request, checked against the refund policy (cutoff before the event; unwatched rentals only).
     * Empty lists = every eligible ticket and rental in the order.
     *
     * @param  list<int>  $ticketIds
     * @param  list<int>  $rentalIds
     */
    public function request(User $customer, Order $order, array $ticketIds, array $rentalIds, string $reason): Refund
    {
        return $this->create($order, $ticketIds, $rentalIds, $reason, $customer, enforcePolicy: true, includeFees: false);
    }

    /** Staff refund: no policy limits, optional booking-fee refund, approved and sent to the provider at once. */
    public function refundByStaff(User $staff, Order $order, array $ticketIds, array $rentalIds, string $reason, bool $includeFees): Refund
    {
        $refund = $this->create($order, $ticketIds, $rentalIds, $reason, $staff, enforcePolicy: false, includeFees: $includeFees);

        return $this->approve($refund, $staff, 'Issued by staff');
    }

    public function reject(Refund $refund, User $staff, string $note): Refund
    {
        $refund = DB::transaction(function () use ($refund, $staff, $note) {
            $locked = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== Refund::REQUESTED) {
                throw new ApiException(409, 'invalid_status_change', "This refund is already {$locked->status}.");
            }
            $locked->update(['status' => Refund::REJECTED, 'review_note' => $note, 'reviewed_by' => $staff->id, 'reviewed_at' => now()]);
            $this->audit->record('refund.rejected', $locked, ['note' => $note]);

            return $locked;
        });

        return $refund->load('order', 'items');
    }

    /** Lock what is being refunded, then ask the provider to return the money. */
    public function approve(Refund $refund, User $staff, ?string $note = null): Refund
    {
        $refund = DB::transaction(function () use ($refund, $staff, $note) {
            $locked = Refund::whereKey($refund->id)->lockForUpdate()->with('items')->firstOrFail();
            if ($locked->status !== Refund::REQUESTED) {
                throw new ApiException(409, 'invalid_status_change', "This refund is already {$locked->status}.");
            }

            foreach ($locked->items as $item) {
                if ($item->ticket_id) {
                    $changed = Ticket::whereKey($item->ticket_id)->where('status', Ticket::VALID)->update(['status' => Ticket::REFUND_PENDING]);
                    if ($changed !== 1) {
                        throw new ApiException(409, 'ticket_not_refundable', "Ticket #{$item->ticket_id} has been used or cancelled since the request.");
                    }
                }
                if ($item->rental_id) {
                    $changed = Rental::whereKey($item->rental_id)->where('status', Rental::READY)->update(['status' => Rental::REVOKED]);
                    if ($changed !== 1) {
                        throw new ApiException(409, 'rental_not_refundable', "Rental #{$item->rental_id} has started or ended since the request.");
                    }
                }
            }

            $locked->update(['status' => Refund::PROCESSING, 'review_note' => $note, 'reviewed_by' => $staff->id, 'reviewed_at' => now()]);
            $this->audit->record('refund.approved', $locked, ['amount' => $locked->amount, 'currency' => $locked->currency]);

            return $locked;
        });

        $this->sendToProvider($refund);

        return $refund->fresh(['order', 'items']);
    }

    /** Re-check refunds the provider is still working on (scheduled). */
    public function syncPending(): int
    {
        $done = 0;
        foreach (Refund::where('status', Refund::PROCESSING)->whereNotNull('provider_refund_id')->with('payment')->get() as $refund) {
            $gateway = $this->gateways->driver($refund->payment->provider);
            if (! method_exists($gateway, 'refundStatus')) {
                continue;
            }
            $result = $gateway->refundStatus($refund->provider_refund_id);
            if ($result->status !== RefundResult::PENDING) {
                $this->settle($refund, $result);
                $done++;
            }
        }

        return $done;
    }

    private function sendToProvider(Refund $refund): void
    {
        $payment = $refund->payment;
        try {
            $result = $this->gateways->driver($payment->provider)
                ->refund($payment, Money::of((string) $refund->amount, $refund->currency), "refund-{$refund->id}");
        } catch (Throwable $e) {
            report($e);
            $result = new RefundResult(RefundResult::FAILED, reason: 'provider_unreachable');
        }

        if ($result->status === RefundResult::PENDING) {
            $refund->update(['provider_refund_id' => $result->providerRefundId]);

            return;
        }
        $this->settle($refund, $result);
    }

    private function settle(Refund $refund, RefundResult $result): void
    {
        DB::transaction(function () use ($refund, $result) {
            $locked = Refund::whereKey($refund->id)->lockForUpdate()->with('items.orderItem')->firstOrFail();
            if ($locked->status !== Refund::PROCESSING) {
                return;
            }

            if ($result->status === RefundResult::FAILED) {
                foreach ($locked->items as $item) {
                    if ($item->ticket_id) {
                        Ticket::whereKey($item->ticket_id)->where('status', Ticket::REFUND_PENDING)->update(['status' => Ticket::VALID]);
                    }
                    if ($item->rental_id) {
                        Rental::whereKey($item->rental_id)->where('status', Rental::REVOKED)->update(['status' => Rental::READY]);
                    }
                }
                $locked->update(['status' => Refund::FAILED, 'failure_reason' => $result->reason ?? 'provider_failed',
                    'provider_refund_id' => $result->providerRefundId ?? $locked->provider_refund_id]);
                $this->audit->record('refund.failed', $locked, ['reason' => $result->reason]);

                return;
            }

            foreach ($locked->items as $item) {
                if ($item->ticket_id) {
                    $ticket = Ticket::whereKey($item->ticket_id)->lockForUpdate()->firstOrFail();
                    $ticket->update(['status' => Ticket::REFUNDED]);
                    $event = Event::with('organizer')->findOrFail($ticket->event_id);
                    // A refunded ticket for a future event goes back on sale.
                    $type = TicketType::whereKey($ticket->ticket_type_id)->lockForUpdate()->firstOrFail();
                    if ($event->starts_at->isFuture() && $type->quantity_sold > 0) {
                        $type->decrement('quantity_sold');
                    }
                    $this->ledger->postTicketRefund($item->id, $item->orderItem, $event, Money::of((string) $item->base_amount, config('marketplace.currency')));
                }
            }

            $locked->update(['status' => Refund::SUCCEEDED, 'completed_at' => now(),
                'provider_refund_id' => $result->providerRefundId ?? $locked->provider_refund_id]);
            $this->updateOrderStatus($locked->order_id);
            $this->audit->record('refund.succeeded', $locked, ['amount' => $locked->amount, 'currency' => $locked->currency]);
        });
    }

    private function updateOrderStatus(int $orderId): void
    {
        $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
        $refunded = Money::ofMinor(0, $order->currency);
        foreach (Refund::where('order_id', $orderId)->where('status', Refund::SUCCEEDED)->pluck('amount') as $amount) {
            $refunded = $refunded->add(Money::of((string) $amount, $order->currency));
        }
        $next = $refunded->minor >= $order->totalMoney()->minor ? OrderStatus::Refunded : OrderStatus::PartiallyRefunded;
        if ($order->status !== $next && $order->status->canTransitionTo($next)) {
            $order->transitionTo($next);
        }
    }

    /**
     * @param  list<int>  $ticketIds
     * @param  list<int>  $rentalIds
     */
    private function create(Order $order, array $ticketIds, array $rentalIds, string $reason, User $by, bool $enforcePolicy, bool $includeFees): Refund
    {
        if (! in_array($order->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded], true)) {
            throw new ApiException(409, 'order_not_refundable', 'Only paid orders can be refunded.');
        }
        $payment = $order->succeededPayment()->first();
        if (! $payment) {
            throw new ApiException(409, 'order_not_refundable', 'This order has no completed payment to refund.');
        }

        return DB::transaction(function () use ($order, $payment, $ticketIds, $rentalIds, $reason, $by, $enforcePolicy, $includeFees) {
            Order::whereKey($order->id)->lockForUpdate()->first(); // one refund decision per order at a time
            $open = RefundItem::whereHas('refund', fn ($q) => $q->where('order_id', $order->id)->whereIn('status', [...Refund::OPEN, Refund::SUCCEEDED]));
            $busyTickets = (clone $open)->whereNotNull('ticket_id')->pluck('ticket_id')->all();
            $busyRentals = (clone $open)->whereNotNull('rental_id')->pluck('rental_id')->all();
            $explicit = $ticketIds || $rentalIds;

            $tickets = Ticket::where('order_id', $order->id)->where('status', Ticket::VALID)
                ->when($explicit, fn ($q) => $q->whereIn('id', $ticketIds ?: [0]))->whereNotIn('id', $busyTickets)->with('event')->get();
            $rentals = Rental::where('order_id', $order->id)->where('status', Rental::READY)
                ->when($explicit, fn ($q) => $q->whereIn('id', $rentalIds ?: [0]))->whereNotIn('id', $busyRentals)->get();

            if ($explicit && ($tickets->count() !== count(array_unique($ticketIds)) || $rentals->count() !== count(array_unique($rentalIds)))) {
                throw new ApiException(422, 'not_refundable', 'Some of these tickets or rentals are not in this order, already used, or already being refunded.');
            }

            if ($enforcePolicy) {
                $cutoff = now()->addHours((int) $this->settings->get('ticket_refund_cutoff_hours'));
                $tooLate = $tickets->filter(fn (Ticket $t) => $t->event->starts_at->lte($cutoff));
                $windowEnd = $order->paid_at?->copy()->addHours((int) $this->settings->get('rental_refund_window_hours'));
                if ($explicit && $tooLate->isNotEmpty()) {
                    throw new ApiException(422, 'refund_window_closed', 'Ticket refunds close '.$this->settings->get('ticket_refund_cutoff_hours').' hours before the event.');
                }
                if ($rentals->isNotEmpty() && (! $windowEnd || $windowEnd->isPast())) {
                    if ($explicit) {
                        throw new ApiException(422, 'refund_window_closed', 'Unwatched rentals can be refunded within '.$this->settings->get('rental_refund_window_hours').' hours of purchase.');
                    }
                    $rentals = $rentals->take(0);
                }
                $tickets = $tickets->reject(fn (Ticket $t) => $tooLate->contains($t));
            }

            if ($tickets->isEmpty() && $rentals->isEmpty()) {
                throw new ApiException(422, 'nothing_to_refund', 'Nothing in this order can be refunded.');
            }

            $items = $order->items()->get()->keyBy('id');
            $lines = [];
            foreach ($tickets as $t) {
                $lines[] = ['order_item_id' => $t->order_item_id, 'ticket_id' => $t->id, 'rental_id' => null];
            }
            foreach ($rentals as $r) {
                $lines[] = ['order_item_id' => $r->order_item_id, 'ticket_id' => null, 'rental_id' => $r->id];
            }

            $amount = Money::ofMinor(0, $order->currency);
            $base = Money::ofMinor(0, config('marketplace.currency'));
            foreach ($lines as &$line) {
                $oi = $items[$line['order_item_id']];
                $line['amount'] = Money::of((string) $oi->unit_price, $order->currency);
                $line['base_amount'] = Money::of((string) ($oi->base_unit_price ?? $oi->unit_price), config('marketplace.currency'));
                $amount = $amount->add($line['amount']);
                $base = $base->add($line['base_amount']);
            }
            unset($line);

            $feesIncluded = false;
            if ($includeFees && ! Refund::where('order_id', $order->id)->where('includes_fees', true)->whereIn('status', [...Refund::OPEN, Refund::SUCCEEDED])->exists()) {
                $amount = $amount->add(Money::of((string) $order->fee_total, $order->currency));
                $base = $base->add(Money::of((string) $order->base_fee_total, config('marketplace.currency')));
                $feesIncluded = true;
            }

            $refund = Refund::create([
                'order_id' => $order->id, 'payment_id' => $payment->id, 'status' => Refund::REQUESTED,
                'amount' => $amount->toDecimal(), 'currency' => $order->currency, 'base_amount' => $base->toDecimal(),
                'includes_fees' => $feesIncluded, 'reason' => $reason, 'requested_by' => $by->id,
            ]);
            foreach ($lines as $line) {
                $refund->items()->create([
                    'order_item_id' => $line['order_item_id'], 'ticket_id' => $line['ticket_id'], 'rental_id' => $line['rental_id'],
                    'amount' => $line['amount']->toDecimal(), 'base_amount' => $line['base_amount']->toDecimal(),
                ]);
            }
            $this->audit->record('refund.requested', $refund, ['amount' => $amount->toArray(), 'lines' => count($lines), 'by' => $by->id]);

            return $refund;
        });
    }
}
