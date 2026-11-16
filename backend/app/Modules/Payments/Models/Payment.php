<?php

namespace App\Modules\Payments\Models;

use App\Modules\Orders\Models\Order;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    public const INITIATED = 'initiated';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** Provider says paid, but amount/currency differ from the order: held for finance review, never fulfilled. */
    public const REVIEW = 'review';

    protected $fillable = [
        'order_id', 'provider', 'provider_reference', 'provider_payment_id', 'status', 'amount', 'currency', 'checkout_url',
        'failure_reason', 'verified_at',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'verified_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::SUCCEEDED, self::REVIEW], true);
    }
}
