<?php

namespace App\Modules\Finance\Models;

use App\Modules\Organizers\Models\Organizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payout extends Model
{
    protected $fillable = ['organizer_id', 'amount', 'currency', 'reference', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }
}
