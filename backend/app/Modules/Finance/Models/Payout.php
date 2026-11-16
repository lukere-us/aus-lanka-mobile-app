<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class Payout extends Model
{
    protected $fillable = ['organizer_id', 'amount', 'currency', 'reference', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
