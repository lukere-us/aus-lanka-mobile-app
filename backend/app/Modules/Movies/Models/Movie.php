<?php

namespace App\Modules\Movies\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Movie extends Model
{
    use SoftDeletes;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const UNPUBLISHED = 'unpublished';

    protected $fillable = [
        'slug', 'title', 'synopsis', 'release_year', 'runtime_minutes', 'classification', 'genres', 'languages',
        'poster_url', 'backdrop_url', 'trailer_url', 'status', 'published_at',
    ];

    protected function casts(): array
    {
        return ['genres' => 'array', 'languages' => 'array', 'published_at' => 'datetime', 'release_year' => 'integer', 'runtime_minutes' => 'integer'];
    }

    public function assets(): HasMany
    {
        return $this->hasMany(MovieAsset::class);
    }

    public function feature(): HasOne
    {
        return $this->hasOne(MovieAsset::class)->ofMany(['id' => 'max'], fn ($q) => $q->where('kind', 'feature')->where('status', 'ready'));
    }

    public function packages(): HasMany
    {
        return $this->hasMany(RentalPackage::class)->orderBy('price');
    }

    public function scopePublished(Builder $q): void
    {
        $q->where('status', self::PUBLISHED);
    }

    public function isRentable(): bool
    {
        return $this->status === self::PUBLISHED && $this->feature()->exists();
    }
}
