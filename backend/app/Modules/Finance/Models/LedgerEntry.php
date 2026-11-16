<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'organizer_ledger_entries';

    protected $fillable = [
        'organizer_id', 'event_id', 'type', 'amount', 'currency', 'commission_bps', 'order_id', 'payout_id', 'source_key', 'description',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'commission_bps' => 'integer'];
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id, 'type' => $this->type, 'amount' => ['amount' => $this->amount, 'currency' => $this->currency],
            'event_id' => $this->event_id, 'order_id' => $this->order_id, 'payout_id' => $this->payout_id,
            'description' => $this->description, 'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
