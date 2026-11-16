<?php

namespace App\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Inbox of provider notifications. Unique (provider, event_id) makes redelivery harmless (D04). */
class PaymentEvent extends Model
{
    protected $fillable = [
        'provider', 'event_id', 'type', 'provider_reference', 'payment_id', 'payload', 'processed_at',
        'outcome', 'attempts', 'last_error',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime', 'attempts' => 'integer'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
