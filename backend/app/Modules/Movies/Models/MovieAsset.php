<?php

namespace App\Modules\Movies\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovieAsset extends Model
{
    protected $fillable = ['movie_id', 'kind', 'provider', 'provider_asset_id', 'status', 'duration_seconds', 'subtitles'];

    protected $hidden = ['provider_asset_id'];

    protected function casts(): array
    {
        return ['subtitles' => 'array', 'duration_seconds' => 'integer'];
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }
}
