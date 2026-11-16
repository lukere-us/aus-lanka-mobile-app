<?php

namespace App\Modules\Refunds\Models;

use App\Modules\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundItem extends Model
{
    protected $fillable = ['refund_id', 'order_item_id', 'ticket_id', 'rental_id', 'amount', 'base_amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'base_amount' => 'decimal:2'];
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
