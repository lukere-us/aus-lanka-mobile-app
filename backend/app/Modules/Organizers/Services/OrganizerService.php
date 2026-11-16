<?php

namespace App\Modules\Organizers\Services;

use App\Http\Responses\ApiException;
use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\Role;
use App\Modules\Organizers\Models\Organizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganizerService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array{name: string, contact_email: string, contact_phone?: ?string, business_registration_no?: ?string} $data */
    public function apply(User $user, array $data): Organizer
    {
        if (Organizer::where('owner_id', $user->id)->exists()) {
            throw new ApiException(409, 'already_applied', 'You have already applied as an organizer.');
        }

        return DB::transaction(function () use ($user, $data) {
            $organizer = Organizer::create($data + [
                'owner_id' => $user->id,
                'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(5)),
                'status' => Organizer::PENDING,
            ]);
            $organizer->members()->attach($user->id, ['role' => Organizer::ROLE_OWNER]);
            $this->audit->record('organizer.applied', $organizer);

            return $organizer;
        });
    }

    public function decide(Organizer $organizer, string $status, ?string $reason, User $admin): Organizer
    {
        $allowed = [
            Organizer::PENDING => [Organizer::APPROVED, Organizer::REJECTED],
            Organizer::APPROVED => [Organizer::SUSPENDED],
            Organizer::SUSPENDED => [Organizer::APPROVED],
            Organizer::REJECTED => [Organizer::APPROVED],
        ];
        if (! in_array($status, $allowed[$organizer->status] ?? [], true)) {
            throw new ApiException(409, 'invalid_status_change', "An organizer that is {$organizer->status} cannot be set to {$status}.");
        }

        return DB::transaction(function () use ($organizer, $status, $reason, $admin) {
            $before = $organizer->status;
            $organizer->forceFill([
                'status' => $status,
                'status_reason' => $reason,
                'approved_at' => $status === Organizer::APPROVED ? now() : $organizer->approved_at,
                'approved_by' => $status === Organizer::APPROVED ? $admin->id : $organizer->approved_by,
            ])->save();

            $owner = $organizer->owner;
            $roleId = Role::where('slug', Role::ORGANIZER)->value('id');
            if ($status === Organizer::APPROVED) {
                $owner->roles()->syncWithoutDetaching([$roleId]);
            } else {
                $owner->roles()->detach($roleId);
            }
            $owner->flushPermissionCache();

            $this->audit->record('organizer.status_changed', $organizer, ['before' => $before, 'after' => $status, 'reason' => $reason]);

            return $organizer;
        });
    }

    public function addStaff(Organizer $organizer, string $email, string $role): User
    {
        $user = User::where('email', mb_strtolower($email))->first();
        if (! $user) {
            throw new ApiException(422, 'user_not_found', 'Ask this person to create an account with that email first.');
        }
        if ($user->id === $organizer->owner_id) {
            throw new ApiException(409, 'already_member', 'The owner is already a member.');
        }

        DB::transaction(function () use ($organizer, $user, $role) {
            $organizer->members()->syncWithoutDetaching([$user->id => ['role' => $role]]);
            $slug = $role === Organizer::ROLE_GATE_STAFF ? Role::GATE_STAFF : Role::ORGANIZER;
            $user->roles()->syncWithoutDetaching([Role::where('slug', $slug)->value('id')]);
            $user->flushPermissionCache();
            $this->audit->record('organizer.staff_added', $organizer, ['user_id' => $user->id, 'role' => $role]);
        });

        return $user;
    }

    public function removeStaff(Organizer $organizer, User $user): void
    {
        if ($user->id === $organizer->owner_id) {
            throw new ApiException(409, 'cannot_remove_owner', 'The owner cannot be removed.');
        }
        DB::transaction(function () use ($organizer, $user) {
            $organizer->members()->detach($user->id);
            $this->audit->record('organizer.staff_removed', $organizer, ['user_id' => $user->id]);
        });
    }
}
