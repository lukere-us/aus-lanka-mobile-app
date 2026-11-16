<?php

namespace App\Modules\Tickets\Models;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\TicketType;
use App\Modules\Orders\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ticket extends Model
{
    public const VALID = 'valid';

    public const USED = 'used';

    public const CANCELLED = 'cancelled';

    public const REFUNDED = 'refunded';

    protected $fillable = [
        'event_id', 'ticket_type_id', 'order_id', 'order_item_id', 'user_id', 'sequence', 'qr_token', 'status',
        'checked_in_at', 'checked_in_by',
    ];

    protected $hidden = ['qr_token'];

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime', 'sequence' => 'integer'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** "TK1." + 32 url-safe chars from 24 random bytes (192 bits). Unguessable, no personal data. */
    public static function newQrToken(): string
    {
        return 'TK1.'.rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }
}
