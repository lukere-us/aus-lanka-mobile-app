<?php

namespace App\Modules\Audit\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Append-only record of privileged, financial and state-changing actions (B06). */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id', 'action', 'auditable_type', 'auditable_id', 'changes', 'ip_address', 'user_agent', 'request_id',
    ];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
