<?php

namespace App\Modules\EventOps\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventStaff extends Model
{
    protected $table = 'event_staff';

    protected $fillable = ['event_id', 'user_id', 'gate_id', 'assigned_by'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function gate(): BelongsTo
    {
        return $this->belongsTo(EventGate::class, 'gate_id');
    }
}
