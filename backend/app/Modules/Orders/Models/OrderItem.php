<?php

namespace App\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Snapshot of what was bought: name and price are copied so later catalogue edits never change an order. */
class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'item_type', 'purchasable_type', 'purchasable_id', 'name', 'unit_price', 'quantity', 'line_total',
        'currency', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'quantity' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function purchasable(): MorphTo
    {
        return $this->morphTo();
    }
}
