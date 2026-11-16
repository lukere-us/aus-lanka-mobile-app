<?php

namespace App\Modules\Finance\Services;

use App\Http\Responses\ApiException;
use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Events\Models\Event;
use App\Modules\Finance\Models\LedgerEntry;
use App\Modules\Finance\Models\Payout;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Organizers\Models\Organizer;
use App\Modules\Settings\Services\PlatformSettings;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Organizer earnings ledger (D07), base currency. Ticket revenue is credited to the organizer, platform commission
 * debited; refunds reverse both; payouts debit. Every posting has a unique source_key, so re-running is harmless.
 */
class LedgerService
{
    public function __construct(private readonly PlatformSettings $settings, private readonly AuditLogger $audit) {}

    public function commissionBps(Organizer $organizer): int
    {
        return $organizer->commission_bps ?? (int) $this->settings->get('ticket_commission_bps');
    }

    /** Called inside the ticket-issuing transaction once tickets exist for this order item. */
    public function postTicketSale(OrderItem $item, Event $event): void
    {
        $organizer = $event->organizer;
        $base = Money::of((string) ($item->base_unit_price ?? $item->unit_price), config('marketplace.currency'))->multiply($item->quantity);
        $bps = $this->commissionBps($organizer);
        $common = ['organizer_id' => $organizer->id, 'event_id' => $event->id, 'currency' => $base->currency, 'order_id' => $item->order_id];

        LedgerEntry::firstOrCreate(['source_key' => "sale:item:{$item->id}"], $common + [
            'type' => 'sale', 'amount' => $base->toDecimal(), 'description' => "{$item->quantity} × {$item->name}",
        ]);
        LedgerEntry::firstOrCreate(['source_key' => "commission:item:{$item->id}"], $common + [
            'type' => 'commission', 'amount' => $base->percentage($bps)->multiply(-1)->toDecimal(), 'commission_bps' => $bps,
            'description' => 'Platform commission '.($bps / 100).'%',
        ]);
    }

    /** Reverse the organizer's share for one refunded ticket line (inside the refund-completion transaction). */
    public function postTicketRefund(int $refundItemId, OrderItem $item, Event $event, Money $baseAmount): void
    {
        $bps = LedgerEntry::where('source_key', "commission:item:{$item->id}")->value('commission_bps') ?? 0;
        $common = ['organizer_id' => $event->organizer_id, 'event_id' => $event->id, 'currency' => $baseAmount->currency, 'order_id' => $item->order_id];

        LedgerEntry::firstOrCreate(['source_key' => "refund:ritem:{$refundItemId}"], $common + [
            'type' => 'refund', 'amount' => $baseAmount->multiply(-1)->toDecimal(), 'description' => "Refund: {$item->name}",
        ]);
        LedgerEntry::firstOrCreate(['source_key' => "commission_reversal:ritem:{$refundItemId}"], $common + [
            'type' => 'commission_reversal', 'amount' => $baseAmount->percentage($bps)->toDecimal(), 'commission_bps' => $bps,
            'description' => 'Commission returned on refund',
        ]);
    }

    /** @return array{total: string, available: string, pending: string, paid_out: string, currency: string, commission_bps: int} */
    public function balance(Organizer $organizer): array
    {
        $holdUntil = now()->subDays((int) $this->settings->get('payout_hold_days'));
        $entries = LedgerEntry::where('organizer_id', $organizer->id)->get(['amount', 'event_id', 'type']);
        $eventStarts = Event::withTrashed()->whereIn('id', $entries->pluck('event_id')->filter()->unique())->pluck('starts_at', 'id');

        $cur = config('marketplace.currency');
        $total = Money::ofMinor(0, $cur);
        $available = Money::ofMinor(0, $cur);
        $paid = Money::ofMinor(0, $cur);
        foreach ($entries as $e) {
            $amount = Money::of((string) $e->amount, $cur);
            $total = $total->add($amount);
            if ($e->event_id === null || ($eventStarts[$e->event_id] ?? now())->lte($holdUntil)) {
                $available = $available->add($amount);
            }
            if ($e->type === 'payout') {
                $paid = $paid->subtract($amount);
            }
        }

        return [
            'total' => $total->toDecimal(),
            'available' => $available->toDecimal(),
            'pending' => $total->subtract($available)->toDecimal(),
            'paid_out' => $paid->toDecimal(),
            'currency' => $cur,
            'commission_bps' => $this->commissionBps($organizer),
        ];
    }

    /** Finance records a bank transfer already made (or about to be) to the organizer. */
    public function recordPayout(Organizer $organizer, string $amount, string $reference, ?string $note, User $by): Payout
    {
        return DB::transaction(function () use ($organizer, $amount, $reference, $note, $by) {
            Organizer::whereKey($organizer->id)->lockForUpdate()->first(); // serialise payouts per organizer
            $money = Money::of($amount, config('marketplace.currency'));
            $available = Money::of($this->balance($organizer)['available'], $money->currency);
            if ($money->minor <= 0 || $money->minor > $available->minor) {
                throw new ApiException(422, 'payout_exceeds_available', "Only {$available->toDecimal()} {$money->currency} is available to pay out.");
            }

            $payout = Payout::create([
                'organizer_id' => $organizer->id, 'amount' => $money->toDecimal(), 'currency' => $money->currency,
                'reference' => $reference, 'note' => $note, 'created_by' => $by->id,
            ]);
            LedgerEntry::create([
                'organizer_id' => $organizer->id, 'type' => 'payout', 'amount' => $money->multiply(-1)->toDecimal(),
                'currency' => $money->currency, 'payout_id' => $payout->id, 'source_key' => "payout:{$payout->id}",
                'description' => "Payout {$reference}",
            ]);
            $this->audit->record('payout.recorded', $payout, ['organizer_id' => $organizer->id, 'amount' => $money->toArray(), 'reference' => $reference]);

            return $payout;
        });
    }
}
