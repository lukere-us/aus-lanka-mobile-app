<?php

namespace App\Modules\Orders\Enums;

enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    /**
     * Allowed state changes. A verified payment that arrives after an order failed or expired is
     * still honoured (the customer's money was taken); fulfillment then decides whether stock is left.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::PendingPayment => [self::Paid, self::Failed, self::Expired, self::Cancelled],
            self::Failed => [self::Paid, self::PendingPayment, self::Expired, self::Cancelled],
            self::Expired => [self::Paid],
            self::Paid => [self::PartiallyRefunded, self::Refunded],
            self::PartiallyRefunded => [self::PartiallyRefunded, self::Refunded],
            self::Cancelled, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function isPayable(): bool
    {
        return in_array($this, [self::PendingPayment, self::Failed], true);
    }
}
