<?php

namespace App\Modules\Audit\Services;

use App\Http\Middleware\AssignRequestId;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditLogger
{
    /**
     * @param  string  $action  dotted verb, e.g. "user.roles_changed", "refund.approved"
     * @param  array<string, mixed>  $changes  typically ['before' => ..., 'after' => ...]
     */
    public function record(string $action, ?Model $subject = null, array $changes = []): AuditLog
    {
        $request = request();

        return AuditLog::create([
            'actor_id' => $request?->user()?->getKey(),
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'changes' => $changes ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
            'request_id' => AssignRequestId::current($request),
        ]);
    }
}
