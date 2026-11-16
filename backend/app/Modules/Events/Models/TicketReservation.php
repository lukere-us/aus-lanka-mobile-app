<?php

namespace App\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketReservation extends Model
{
    public const HELD = 'held';

    public const CONVERTED = 'converted';

    public const RELEASED = 'released';

    protected $fillable = ['ticket_type_id', 'order_id', 'order_item_id', 'quantity', 'status', 'expires_at'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'expires_at' => 'datetime'];
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }
}
