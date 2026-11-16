<?php

namespace App\Modules\Refunds\Models;

use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Refund extends Model
{
    public const REQUESTED = 'requested';

    public const REJECTED = 'rejected';

    public const PROCESSING = 'processing';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const OPEN = [self::REQUESTED, self::PROCESSING];

    protected $fillable = [
        'order_id', 'payment_id', 'status', 'amount', 'currency', 'base_amount', 'includes_fees', 'reason', 'review_note',
        'requested_by', 'reviewed_by', 'reviewed_at', 'provider_refund_id', 'failure_reason', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'base_amount' => 'decimal:2', 'includes_fees' => 'boolean', 'reviewed_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'order_reference' => $this->order?->reference,
            'status' => $this->status,
            'amount' => ['amount' => $this->amount, 'currency' => $this->currency],
            'base_amount' => ['amount' => $this->base_amount, 'currency' => config('marketplace.currency')],
            'includes_fees' => $this->includes_fees,
            'reason' => $this->reason,
            'review_note' => $this->review_note,
            'failure_reason' => $this->failure_reason,
            'ticket_ids' => $this->relationLoaded('items') ? $this->items->pluck('ticket_id')->filter()->values() : null,
            'rental_ids' => $this->relationLoaded('items') ? $this->items->pluck('rental_id')->filter()->values() : null,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
        ];
    }
}
