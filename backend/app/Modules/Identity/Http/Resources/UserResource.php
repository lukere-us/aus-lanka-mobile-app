<?php

namespace App\Modules\Identity\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'email_verified' => $this->email_verified_at !== null,
            'phone_verified' => $this->phone_verified_at !== null,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('slug')->values()),
            'permissions' => $this->when(
                $request->user()?->is($this->resource),
                fn () => $this->resource->isSuperAdmin() ? ['*'] : $this->resource->permissionSlugs(),
            ),
            'last_login_at' => $this->last_login_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
