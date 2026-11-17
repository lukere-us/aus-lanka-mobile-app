<?php

namespace App\Modules\EventOps\Models;

use Illuminate\Database\Eloquent\Model;

class EventGate extends Model
{
    protected $fillable = ['event_id', 'name'];
}
