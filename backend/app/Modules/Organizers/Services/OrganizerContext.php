<?php

namespace App\Modules\Organizers\Services;

use App\Http\Responses\ApiException;
use App\Models\User;
use App\Modules\Organizers\Models\Organizer;

/** Resolves which organizer a portal/scanner user acts for, so every query is scoped to it. */
class OrganizerContext
{
    /** @param list<string> $roles membership roles allowed */
    public function for(User $user, array $roles = [Organizer::ROLE_OWNER, Organizer::ROLE_MANAGER]): Organizer
    {
        $organizer = Organizer::query()
            ->whereHas('members', fn ($q) => $q->where('users.id', $user->id)->whereIn('organizer_members.role', $roles))
            ->first();

        if (! $organizer) {
            throw new ApiException(403, 'not_an_organizer', 'Your account is not set up for an organizer.');
        }
        if ($organizer->status === Organizer::SUSPENDED) {
            throw new ApiException(403, 'organizer_suspended', 'This organizer account is suspended.');
        }

        return $organizer;
    }

    public function isMember(User $user, int $organizerId, ?array $roles = null): bool
    {
        return Organizer::whereKey($organizerId)
            ->whereHas('members', fn ($q) => $q->where('users.id', $user->id)
                ->when($roles, fn ($q) => $q->whereIn('organizer_members.role', $roles)))
            ->exists();
    }
}
