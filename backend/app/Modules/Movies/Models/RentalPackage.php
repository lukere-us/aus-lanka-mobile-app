<?php

namespace App\Modules\Movies\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A way to rent a movie, e.g. "48-hour HD rental, AUD 6.99, start within 30 days". Prices in the base currency. */
class RentalPackage extends Model
{
    protected $fillable = ['movie_id', 'name', 'quality', 'price', 'currency', 'watch_hours', 'start_within_days', 'status'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'watch_hours' => 'integer', 'start_within_days' => 'integer'];
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    public function priceMoney(): Money
    {
        return Money::of($this->price, $this->currency);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'quality' => $this->quality,
            'price' => $this->priceMoney()->toArray(),
            'watch_hours' => $this->watch_hours,
            'start_within_days' => $this->start_within_days,
            'status' => $this->status,
        ];
    }
}
