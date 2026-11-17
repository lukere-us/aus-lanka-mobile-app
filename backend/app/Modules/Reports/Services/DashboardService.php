<?php

namespace App\Modules\Reports\Services;

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Finance\Models\LedgerEntry;
use App\Modules\Identity\Models\Role;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Organizers\Models\Organizer;
use App\Modules\Payments\Models\Payment;
use App\Modules\Refunds\Models\Refund;
use App\Modules\Rentals\Models\Rental;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** Admin dashboard figures (I02). Money in the base currency (AUD), days in the display timezone. */
class DashboardService
{
    public function summary(DateRange $range): array
    {
        $cur = config('marketplace.currency');
        $m = fn ($v) => Money::of(number_format((float) $v, 2, '.', ''), $cur);
        [$from, $to] = $range->bounds();

        $paid = Order::whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to]);
        $paidIds = (clone $paid)->pluck('id');

        $byType = OrderItem::whereIn('order_id', $paidIds)
            ->select('item_type', DB::raw('sum(coalesce(base_unit_price, unit_price) * quantity) as amount'), DB::raw('sum(quantity) as qty'))
            ->groupBy('item_type')->get()->keyBy('item_type');
        $tickets = $m($byType['ticket_type']->amount ?? 0);
        $rentals = $m($byType['rental_package']->amount ?? 0);
        $fees = $m((clone $paid)->sum('base_fee_total'));
        $gross = $tickets->add($rentals)->add($fees);
        $refunds = $m(Refund::where('status', Refund::SUCCEEDED)->whereBetween('completed_at', [$from, $to])->sum('base_amount'));
        $commission = $m(LedgerEntry::whereIn('type', ['commission', 'commission_reversal'])->whereBetween('created_at', [$from, $to])->sum('amount'))->multiply(-1);

        // Platform keeps commission on tickets, all rental revenue and booking fees.
        $platform = $commission->add($rentals)->add($fees);

        return [
            'period' => $range->toApi(),
            'currency' => $cur,
            'revenue' => [
                'gross' => $gross->toDecimal(),
                'tickets' => $tickets->toDecimal(),
                'rentals' => $rentals->toDecimal(),
                'booking_fees' => $fees->toDecimal(),
                'refunds' => $refunds->toDecimal(),
                'net' => $gross->subtract($refunds)->toDecimal(),
                'ticket_commission' => $commission->toDecimal(),
                'platform_revenue_before_refunds' => $platform->toDecimal(),
            ],
            'counts' => [
                'paid_orders' => $paidIds->count(),
                'tickets_sold' => (int) ($byType['ticket_type']->qty ?? 0),
                'rentals_sold' => (int) ($byType['rental_package']->qty ?? 0),
                'new_customers' => User::whereBetween('created_at', [$from, $to])->whereHas('roles', fn ($q) => $q->where('slug', Role::CUSTOMER))->count(),
                'paying_customers' => (clone $paid)->distinct()->count('user_id'),
                'tickets_checked_in' => Ticket::whereBetween('checked_in_at', [$from, $to])->count(),
                'rentals_started' => Rental::whereBetween('activated_at', [$from, $to])->count(),
            ],
            'daily' => $this->daily($range, $paidIds->all()),
            'top_events' => $this->topEvents($paidIds->all()),
            'top_movies' => $this->topMovies($paidIds->all()),
            'needs_attention' => [
                'refund_requests' => Refund::where('status', Refund::REQUESTED)->count(),
                'refunds_failed' => Refund::where('status', Refund::FAILED)->count(),
                'payments_in_review' => Payment::where('status', Payment::REVIEW)->count(),
                'organizers_pending' => Organizer::where('status', Organizer::PENDING)->count(),
                'events_pending_review' => Event::where('status', EventStatus::PendingReview)->count(),
                'unfulfilled_ticket_orders' => DB::table('audit_logs')->where('action', 'tickets.unfulfillable')->count(),
            ],
        ];
    }

    /** @param list<int> $orderIds */
    private function daily(DateRange $range, array $orderIds): array
    {
        $days = array_fill_keys($range->days(), ['orders' => 0, 'gross' => 0]);
        Order::whereIn('id', $orderIds)->select('id', 'paid_at', 'base_total')->chunkById(1000, function ($orders) use (&$days, $range) {
            foreach ($orders as $o) {
                $day = $range->localDay($o->paid_at);
                if (isset($days[$day])) {
                    $days[$day]['orders']++;
                    $days[$day]['gross'] += (int) round(((float) $o->base_total) * 100);
                }
            }
        });

        return collect($days)->map(fn ($d, $day) => ['date' => $day, 'orders' => $d['orders'], 'gross' => number_format($d['gross'] / 100, 2, '.', '')])->values()->all();
    }

    /** @param list<int> $orderIds */
    private function topEvents(array $orderIds): array
    {
        return OrderItem::whereIn('order_items.order_id', $orderIds)->where('item_type', 'ticket_type')
            ->join('ticket_types', 'ticket_types.id', '=', 'order_items.purchasable_id')
            ->join('events', 'events.id', '=', 'ticket_types.event_id')
            ->select('events.id', 'events.title', DB::raw('sum(order_items.quantity) as tickets'),
                DB::raw('sum(coalesce(order_items.base_unit_price, order_items.unit_price) * order_items.quantity) as revenue'))
            ->groupBy('events.id', 'events.title')->orderByDesc('tickets')->limit(5)->get()
            ->map(fn ($r) => ['event_id' => $r->id, 'title' => $r->title, 'tickets' => (int) $r->tickets, 'revenue' => number_format((float) $r->revenue, 2, '.', '')])->all();
    }

    /** @param list<int> $orderIds */
    private function topMovies(array $orderIds): array
    {
        return OrderItem::whereIn('order_items.order_id', $orderIds)->where('item_type', 'rental_package')
            ->join('rental_packages', 'rental_packages.id', '=', 'order_items.purchasable_id')
            ->join('movies', 'movies.id', '=', 'rental_packages.movie_id')
            ->select('movies.id', 'movies.title', DB::raw('sum(order_items.quantity) as rentals'),
                DB::raw('sum(coalesce(order_items.base_unit_price, order_items.unit_price) * order_items.quantity) as revenue'))
            ->groupBy('movies.id', 'movies.title')->orderByDesc('rentals')->limit(5)->get()
            ->map(fn ($r) => ['movie_id' => $r->id, 'title' => $r->title, 'rentals' => (int) $r->rentals, 'revenue' => number_format((float) $r->revenue, 2, '.', '')])->all();
    }
}
