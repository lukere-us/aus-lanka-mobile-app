<?php

namespace App\Modules\Events\Models;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Organizers\Models\Organizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organizer_id', 'venue_id', 'slug', 'title', 'description', 'category', 'image_url', 'starts_at', 'ends_at',
        'doors_open_at', 'status', 'review_note', 'terms', 'min_age', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'doors_open_at' => 'datetime',
            'published_at' => 'datetime',
            'min_age' => 'integer',
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Published, not yet started, organizer in good standing: what customers may see and buy. */
    public function scopeOnSale(Builder $query): void
    {
        $query->where('status', EventStatus::Published)
            ->where('starts_at', '>', now())
            ->whereHas('organizer', fn ($q) => $q->where('status', Organizer::APPROVED));
    }

    public function isOnSale(): bool
    {
        return $this->status === EventStatus::Published && $this->starts_at->isFuture() && $this->organizer->isApproved();
    }
}
