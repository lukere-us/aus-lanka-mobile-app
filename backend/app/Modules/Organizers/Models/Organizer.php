<?php

namespace App\Modules\Organizers\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Organizer extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const SUSPENDED = 'suspended';

    public const ROLE_OWNER = 'owner';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_GATE_STAFF = 'gate_staff';

    protected $fillable = [
        'owner_id', 'name', 'slug', 'contact_email', 'contact_phone', 'business_registration_no', 'status',
        'status_reason', 'approved_at', 'approved_by',
    ];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organizer_members')->withPivot('role')->withTimestamps();
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }
}
