<?php

namespace App\Modules\Rentals\Models;

use App\Models\User;
use App\Modules\Movies\Models\Movie;
use App\Modules\Movies\Models\RentalPackage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rental extends Model
{
    public const READY = 'ready';       // paid, not started yet

    public const ACTIVE = 'active';     // viewing window running

    public const EXPIRED = 'expired';

    public const REVOKED = 'revoked';   // refunded / removed by admin

    protected $fillable = [
        'user_id', 'movie_id', 'rental_package_id', 'order_id', 'order_item_id', 'status', 'watch_hours',
        'activate_by', 'activated_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['activate_by' => 'datetime', 'activated_at' => 'datetime', 'expires_at' => 'datetime', 'watch_hours' => 'integer'];
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(RentalPackage::class, 'rental_package_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Status as of now, even if the scheduled expiry job has not run yet. */
    public function effectiveStatus(): string
    {
        return match (true) {
            $this->status === self::ACTIVE && $this->expires_at?->isPast() => self::EXPIRED,
            $this->status === self::READY && $this->activate_by->isPast() => self::EXPIRED,
            default => $this->status,
        };
    }
}
