<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiException;
use App\Models\User;
use App\Modules\Admin\Http\Requests\UpdateUserRolesRequest;
use App\Modules\Admin\Http\Requests\UpdateUserStatusRequest;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class UserManagementController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly AuthService $auth) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'exists:roles,slug'],
            'status' => ['nullable', 'in:active,suspended'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->with('roles')
            ->when($validated['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")))
            ->when($validated['role'] ?? null, fn ($q, $r) => $q->whereHas('roles', fn ($q) => $q->where('slug', $r)))
            ->when($validated['status'] ?? null, fn ($q, $st) => $q->where('status', $st))
            ->latest('id')
            ->paginate($validated['per_page'] ?? 25)
            ->withQueryString();

        return UserResource::collection($users);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load('roles'));
    }

    public function updateRoles(UpdateUserRolesRequest $request, User $user): UserResource
    {
        $before = $user->roles()->pluck('slug')->sort()->values()->all();
        $after = collect($request->validated('roles'))->sort()->values()->all();

        DB::transaction(function () use ($user, $after, $before) {
            $user->roles()->sync(Role::whereIn('slug', $after)->pluck('id'));
            $this->audit->record('user.roles_changed', $user, ['before' => $before, 'after' => $after]);
        });
        $user->flushPermissionCache();

        return new UserResource($user->load('roles'));
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): UserResource
    {
        if ($user->is($request->user())) {
            throw new ApiException(409, 'cannot_change_own_status', 'You cannot change the status of your own account.');
        }
        if ($user->isSuperAdmin() && ! $request->user()->isSuperAdmin()) {
            throw new ApiException(403, 'forbidden', 'Only a Super Admin can change another Super Admin.');
        }

        $before = $user->status;
        $after = $request->validated('status');

        DB::transaction(function () use ($user, $before, $after, $request) {
            $user->forceFill(['status' => $after])->save();
            $this->audit->record('user.status_changed', $user, [
                'before' => $before, 'after' => $after, 'reason' => $request->validated('reason'),
            ]);
            if ($after === User::STATUS_SUSPENDED) {
                $this->auth->revokeAllTokens($user, 'suspended');
            }
        });

        return new UserResource($user->load('roles'));
    }
}
