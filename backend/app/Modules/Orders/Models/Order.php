<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Models\Payment;
use App\Support\Money;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = [
        'reference', 'user_id', 'status', 'currency', 'base_currency', 'exchange_rate', 'base_total', 'subtotal', 'discount_total', 'fee_total', 'total',
        'idempotency_key', 'request_hash', 'expires_at', 'paid_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'fee_total' => 'decimal:2',
            'total' => 'decimal:2',
            'base_total' => 'decimal:2',
            'exchange_rate' => 'decimal:8',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function totalMoney(): Money
    {
        return Money::of($this->total, $this->currency);
    }

    /** Validated state change; callers audit it. */
    public function transitionTo(OrderStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw new DomainException("Order {$this->reference} cannot go from {$this->status->value} to {$next->value}.");
        }

        $this->status = $next;
        match ($next) {
            OrderStatus::Paid => $this->paid_at = now(),
            OrderStatus::Cancelled => $this->cancelled_at = now(),
            default => null,
        };
        $this->save();
    }
}
