<?php

namespace App\Modules\Rentals\Models;

use Illuminate\Database\Eloquent\Model;

class PlaybackProgress extends Model
{
    protected $table = 'playback_progress';

    protected $fillable = ['user_id', 'movie_id', 'position_seconds', 'completed'];

    protected function casts(): array
    {
        return ['position_seconds' => 'integer', 'completed' => 'boolean'];
    }
}
