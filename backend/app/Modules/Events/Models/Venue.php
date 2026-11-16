<?php

namespace App\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;

class Venue extends Model
{
    protected $fillable = ['organizer_id', 'name', 'address', 'city', 'latitude', 'longitude', 'capacity'];

    protected function casts(): array
    {
        return ['latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'capacity' => 'integer'];
    }
}
