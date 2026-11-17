<?php

namespace App\Modules\EventOps\Models;

use Illuminate\Database\Eloquent\Model;

class ScannerDevice extends Model
{
    protected $fillable = ['organizer_id', 'registered_by', 'device_uid', 'name', 'last_used_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
