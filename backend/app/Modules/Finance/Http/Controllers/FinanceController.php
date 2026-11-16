<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Finance\Models\LedgerEntry;
use App\Modules\Finance\Models\Payout;
use App\Modules\Finance\Services\LedgerService;
use App\Modules\Organizers\Models\Organizer;
use App\Modules\Organizers\Services\OrganizerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Organizer balances, ledger and payouts (D07, I06 API, organizer payout visibility). */
class FinanceController extends Controller
{
    public function __construct(private readonly LedgerService $ledger, private readonly OrganizerContext $context, private readonly AuditLogger $audit) {}

    public function myBalance(Request $request): JsonResponse
    {
        return $this->balanceFor($this->context->for($request->user()));
    }

    public function myLedger(Request $request): JsonResponse
    {
        return $this->ledgerFor($this->context->for($request->user()));
    }

    public function balance(Organizer $organizer): JsonResponse
    {
        return $this->balanceFor($organizer);
    }

    public function ledger(Organizer $organizer): JsonResponse
    {
        return $this->ledgerFor($organizer);
    }

    public function recordPayout(Request $request, Organizer $organizer): JsonResponse
    {
        $v = $request->validate([
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'reference' => ['required', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $payout = $this->ledger->recordPayout($organizer, (string) $v['amount'], $v['reference'], $v['note'] ?? null, $request->user());

        return response()->json(['data' => $payout->only(['id', 'organizer_id', 'amount', 'currency', 'reference', 'note', 'created_at'])], 201);
    }

    public function payouts(Request $request): JsonResponse
    {
        $page = Payout::latest('id')->when($request->integer('organizer_id'), fn ($q, $id) => $q->where('organizer_id', $id))->paginate(25);

        return response()->json(['data' => $page->items(), 'meta' => ['total' => $page->total()]]);
    }

    public function setCommission(Request $request, Organizer $organizer): JsonResponse
    {
        $v = $request->validate(['commission_bps' => ['present', 'nullable', 'integer', 'min:0', 'max:5000']]);
        $before = $organizer->commission_bps;
        $organizer->update(['commission_bps' => $v['commission_bps']]);
        $this->audit->record('organizer.commission_changed', $organizer, ['before' => $before, 'after' => $v['commission_bps']]);

        return $this->balanceFor($organizer);
    }

    private function balanceFor(Organizer $organizer): JsonResponse
    {
        return response()->json(['data' => ['organizer_id' => $organizer->id] + $this->ledger->balance($organizer)]);
    }

    private function ledgerFor(Organizer $organizer): JsonResponse
    {
        $page = LedgerEntry::where('organizer_id', $organizer->id)->latest('id')->paginate(50);

        return response()->json(['data' => collect($page->items())->map->toApi(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage()]]);
    }
}
