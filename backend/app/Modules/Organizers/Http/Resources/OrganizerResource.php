<?php

namespace App\Modules\Organizers\Http\Resources;

use App\Modules\Organizers\Models\Organizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Organizer */
class OrganizerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
            'status_reason' => $this->status_reason,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'business_registration_no' => $this->business_registration_no,
            'approved_at' => $this->approved_at?->toIso8601ZuluString(),
            'members' => $this->whenLoaded('members', fn () => $this->members->map(fn ($m) => [
                'user_id' => $m->id, 'name' => $m->name, 'email' => $m->email, 'role' => $m->pivot->role,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
