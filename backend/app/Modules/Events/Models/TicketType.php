<?php

namespace App\Modules\Events\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketType extends Model
{
    public const KINDS = ['general', 'early_bird', 'vip', 'vvip', 'custom'];

    protected $fillable = [
        'event_id', 'name', 'kind', 'description', 'price', 'currency', 'quantity_total', 'max_per_order',
        'sale_starts_at', 'sale_ends_at', 'status', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'quantity_total' => 'integer',
            'quantity_sold' => 'integer',
            'quantity_reserved' => 'integer',
            'max_per_order' => 'integer',
            'sale_starts_at' => 'datetime',
            'sale_ends_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function available(): int
    {
        return max(0, $this->quantity_total - $this->quantity_sold - $this->quantity_reserved);
    }

    public function priceMoney(): Money
    {
        return Money::of($this->price, $this->currency);
    }

    public function saleState(): string
    {
        return match (true) {
            $this->status !== 'active' => 'unavailable',
            $this->sale_starts_at?->isFuture() ?? false => 'not_started',
            $this->sale_ends_at?->isPast() ?? false => 'ended',
            $this->available() === 0 => 'sold_out',
            default => 'on_sale',
        };
    }
}
