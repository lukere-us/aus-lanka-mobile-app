<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Events\Models\TicketType;
use App\Modules\Finance\Models\Payout;
use App\Modules\Finance\Services\LedgerService;
use App\Modules\Orders\Models\Order;
use App\Modules\Organizers\Models\Organizer;
use App\Modules\Refunds\Models\Refund;
use App\Modules\Rentals\Models\Rental;
use App\Modules\Reports\Services\DashboardService;
use App\Modules\Reports\Services\DateRange;
use App\Support\Csv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Admin dashboard (I02), finance CSV exports and audit log (I08). Periods are local days (?from=&to=, Y-m-d). */
class ReportsController extends Controller
{
    public function dashboard(Request $request, DashboardService $dashboard): JsonResponse
    {
        return response()->json(['data' => $dashboard->summary(DateRange::fromRequest($request))]);
    }

    public function orders(Request $request): StreamedResponse
    {
        $range = DateRange::fromRequest($request);
        $rows = function () use ($range) {
            foreach (Order::whereBetween('created_at', $range->bounds())->with(['user', 'latestPayment'])->orderBy('id')->lazyById(500) as $o) {
                yield [$o->reference, $o->created_at?->toIso8601ZuluString(), $o->paid_at?->toIso8601ZuluString(), $o->status->value,
                    $o->user?->email, $o->currency, $o->subtotal, $o->fee_total, $o->total, $o->exchange_rate, $o->base_total,
                    $o->latestPayment?->provider, $o->latestPayment?->status];
            }
        };

        return Csv::download("orders-{$this->suffix($range)}.csv",
            ['Reference', 'Created (UTC)', 'Paid (UTC)', 'Status', 'Customer email', 'Currency', 'Subtotal', 'Booking fee', 'Total', 'Rate per AUD', 'Total (AUD)', 'Gateway', 'Payment status'], $rows());
    }

    public function refunds(Request $request): StreamedResponse
    {
        $range = DateRange::fromRequest($request);
        $rows = function () use ($range) {
            foreach (Refund::whereBetween('created_at', $range->bounds())->with('order')->orderBy('id')->lazyById(500) as $r) {
                yield [$r->id, $r->order?->reference, $r->status, $r->created_at?->toIso8601ZuluString(), $r->completed_at?->toIso8601ZuluString(),
                    $r->currency, $r->amount, $r->base_amount, $r->includes_fees ? 'yes' : 'no', $r->reason, $r->review_note, $r->failure_reason, $r->provider_refund_id];
            }
        };

        return Csv::download("refunds-{$this->suffix($range)}.csv",
            ['Refund ID', 'Order', 'Status', 'Requested (UTC)', 'Completed (UTC)', 'Currency', 'Amount', 'Amount (AUD)', 'Includes fees', 'Reason', 'Review note', 'Failure', 'Provider refund ID'], $rows());
    }

    public function payouts(Request $request): StreamedResponse
    {
        $range = DateRange::fromRequest($request);
        $names = Organizer::pluck('name', 'id');
        $rows = function () use ($range, $names) {
            foreach (Payout::whereBetween('created_at', $range->bounds())->orderBy('id')->lazyById(500) as $p) {
                yield [$p->id, $p->created_at?->toIso8601ZuluString(), $names[$p->organizer_id] ?? $p->organizer_id, $p->currency, $p->amount, $p->reference, $p->note];
            }
        };

        return Csv::download("payouts-{$this->suffix($range)}.csv", ['Payout ID', 'Recorded (UTC)', 'Organizer', 'Currency', 'Amount', 'Bank reference', 'Note'], $rows());
    }

    /** Current balance of every organizer (no period). */
    public function organizerBalances(LedgerService $ledger): StreamedResponse
    {
        $rows = function () use ($ledger) {
            foreach (Organizer::orderBy('name')->lazyById(200) as $o) {
                $b = $ledger->balance($o);
                yield [$o->id, $o->name, $o->status, $b['commission_bps'] / 100, $b['currency'], $b['total'], $b['available'], $b['pending'], $b['paid_out']];
            }
        };

        return Csv::download('organizer-balances-'.now()->format('Y-m-d').'.csv',
            ['Organizer ID', 'Organizer', 'Status', 'Commission %', 'Currency', 'Balance', 'Available to pay', 'Pending (event not held)', 'Paid out to date'], $rows());
    }

    /** Stock and sales per ticket type for events starting in the period. */
    public function ticketSales(Request $request): StreamedResponse
    {
        $range = DateRange::fromRequest($request);
        $rows = function () use ($range) {
            $types = TicketType::whereHas('event', fn ($q) => $q->whereBetween('starts_at', $range->bounds()))
                ->with('event.organizer')->orderBy('event_id')->orderBy('sort_order')->get();
            foreach ($types as $t) {
                yield [$t->event->id, $t->event->title, $t->event->organizer->name, $t->event->starts_at->toIso8601ZuluString(), $t->event->status->value,
                    $t->name, $t->currency, $t->price, $t->quantity_total, $t->quantity_sold, $t->quantity_reserved, $t->available(),
                    $t->priceMoney()->multiply($t->quantity_sold)->toDecimal()];
            }
        };

        return Csv::download("ticket-sales-{$this->suffix($range)}.csv",
            ['Event ID', 'Event', 'Organizer', 'Starts (UTC)', 'Event status', 'Ticket type', 'Currency', 'Price', 'Total', 'Sold', 'Held in checkout', 'Available', 'Sales value'], $rows());
    }

    public function rentals(Request $request): StreamedResponse
    {
        $range = DateRange::fromRequest($request);
        $rows = function () use ($range) {
            foreach (Rental::whereBetween('created_at', $range->bounds())->with(['movie', 'package'])->orderBy('id')->lazyById(500) as $r) {
                yield [$r->id, $r->created_at?->toIso8601ZuluString(), $r->movie->title, $r->package->name, $r->package->quality, $r->package->currency,
                    $r->package->price, $r->effectiveStatus(), $r->activated_at?->toIso8601ZuluString(), $r->expires_at?->toIso8601ZuluString()];
            }
        };

        return Csv::download("rentals-{$this->suffix($range)}.csv",
            ['Rental ID', 'Purchased (UTC)', 'Movie', 'Package', 'Quality', 'Currency', 'List price', 'Status', 'Started (UTC)', 'Ends (UTC)'], $rows());
    }

    public function auditLogs(Request $request): JsonResponse|StreamedResponse
    {
        $range = DateRange::fromRequest($request);
        $v = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'actor_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'string', 'max:100'],
            'subject_id' => ['nullable', 'integer'],
            'request_id' => ['nullable', 'string', 'max:64'],
            'format' => ['nullable', 'in:json,csv'],
        ]);
        $query = AuditLog::whereBetween('created_at', $range->bounds())
            ->when($v['action'] ?? null, fn ($q, $a) => str_ends_with($a, '.*') ? $q->where('action', 'like', substr($a, 0, -1).'%') : $q->where('action', $a))
            ->when($v['actor_id'] ?? null, fn ($q, $id) => $q->where('actor_id', $id))
            ->when($v['subject_type'] ?? null, fn ($q, $t) => $q->where('auditable_type', 'like', '%'.$t))
            ->when($v['subject_id'] ?? null, fn ($q, $id) => $q->where('auditable_id', $id))
            ->when($v['request_id'] ?? null, fn ($q, $r) => $q->where('request_id', $r))
            ->with('actor:id,name,email')->orderByDesc('id');

        $present = fn (AuditLog $a) => [
            'id' => $a->id, 'at' => $a->created_at?->toIso8601ZuluString(), 'action' => $a->action,
            'actor' => $a->actor ? ['id' => $a->actor->id, 'name' => $a->actor->name, 'email' => $a->actor->email] : null,
            'subject' => $a->auditable_type ? ['type' => class_basename($a->auditable_type), 'id' => $a->auditable_id] : null,
            'changes' => $a->changes, 'ip_address' => $a->ip_address, 'request_id' => $a->request_id,
        ];

        if (($v['format'] ?? 'json') === 'csv') {
            $rows = function () use ($query, $present) {
                foreach ($query->lazy(500) as $a) {
                    $p = $present($a);
                    yield [$p['id'], $p['at'], $p['action'], $p['actor']['email'] ?? 'system', $p['subject']['type'] ?? '', $p['subject']['id'] ?? '',
                        json_encode($p['changes']), $p['ip_address'], $p['request_id']];
                }
            };

            return Csv::download("audit-log-{$this->suffix($range)}.csv", ['ID', 'At (UTC)', 'Action', 'Actor', 'Subject type', 'Subject ID', 'Changes', 'IP', 'Request ID'], $rows());
        }

        $page = $query->paginate(50)->withQueryString();

        return response()->json(['data' => collect($page->items())->map($present), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'period' => $range->toApi()]]);
    }

    private function suffix(DateRange $range): string
    {
        $p = $range->toApi();

        return "{$p['from']}-to-{$p['to']}";
    }
}
