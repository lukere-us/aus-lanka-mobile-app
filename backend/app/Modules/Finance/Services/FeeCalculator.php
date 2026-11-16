<?php

namespace App\Modules\Finance\Services;

use App\Modules\Orders\Support\ResolvedItem;
use App\Modules\Settings\Services\PlatformSettings;
use App\Support\Money;

/** Booking fee charged to the customer on ticket purchases (D07). Rentals carry no booking fee. Base currency. */
class FeeCalculator
{
    public function __construct(private readonly PlatformSettings $settings) {}

    /**
     * @param  list<ResolvedItem>  $items
     * @param  list<string>  $types  item type per index
     */
    public function bookingFee(array $items, array $types, string $baseCurrency): Money
    {
        $tickets = 0;
        $ticketSubtotal = Money::ofMinor(0, $baseCurrency);
        foreach ($items as $i => $item) {
            if ($types[$i] === 'ticket_type') {
                $tickets += $item->quantity;
                $ticketSubtotal = $ticketSubtotal->add($item->lineTotal());
            }
        }
        if ($tickets === 0) {
            return Money::ofMinor(0, $baseCurrency);
        }

        return Money::of((string) $this->settings->get('booking_fee_per_ticket'), $baseCurrency)->multiply($tickets)
            ->add($ticketSubtotal->percentage((int) $this->settings->get('booking_fee_bps')));
    }
}
