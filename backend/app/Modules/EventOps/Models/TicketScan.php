<?php

namespace App\Modules\EventOps\Models;

use Illuminate\Database\Eloquent\Model;

class TicketScan extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['event_id', 'ticket_id', 'gate_id', 'user_id', 'device_id', 'result', 'note'];
}
