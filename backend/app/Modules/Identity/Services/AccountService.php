<?php

namespace App\Modules\Identity\Services;

use App\Http\Responses\ApiException;
use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\DeviceToken;
use App\Modules\Orders\Models\Order;
use App\Modules\Organizers\Models\Organizer;
use App\Modules\Refunds\Models\Refund;
use App\Modules\Rentals\Models\PlaybackProgress;
use App\Modules\Rentals\Models\Rental;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Profile, credentials, devices, data export and account deletion (C04). */
class AccountService
{
    public function __construct(private readonly VerificationService $verification, private readonly AuditLogger $audit) {}

    public function updateProfile(User $user, array $data): User
    {
        $before = $user->only(array_keys($data));
        if (array_key_exists('phone', $data) && $data['phone'] !== $user->phone) {
            $user->phone_verified_at = null; // a new number must be verified again
        }
        $user->fill($data)->save();
        $this->audit->record('user.profile_updated', $user, ['before' => $before, 'after' => $user->only(array_keys($data))]);

        return $user;
    }

    public function changeEmail(User $user, string $password, string $email): ?string
    {
        $this->assertPassword($user, $password);
        $old = $user->email;
        $user->forceFill(['email' => $email, 'email_verified_at' => null])->save();
        $this->audit->record('user.email_changed', $user, ['before' => $old, 'after' => $email]);

        return $this->verification->send($user, 'email');
    }

    public function changePassword(User $user, string $current, string $new): int
    {
        $this->assertPassword($user, $current);
        $user->forceFill(['password' => $new, 'remember_token' => Str::random(60)])->save();

        // Sign out every other device; keep the one making this request.
        $currentId = $user->currentAccessToken()?->id;
        $revoked = $user->tokens()->when($currentId, fn ($q) => $q->where('id', '!=', $currentId))->delete();
        $this->audit->record('user.password_changed', $user, ['other_sessions_revoked' => $revoked]);

        return $revoked;
    }

    public function registerDevice(User $user, string $token, string $platform, ?string $name): DeviceToken
    {
        // A token belongs to one install; if another account used this phone before, it moves to this user.
        return DeviceToken::updateOrCreate(['token' => $token], [
            'user_id' => $user->id, 'platform' => $platform, 'device_name' => $name, 'last_seen_at' => now(),
        ]);
    }

    /** Everything we hold about the person, for a privacy access request. */
    public function export(User $user): array
    {
        $user = $user->fresh();

        return [
            'generated_at' => now()->toIso8601ZuluString(),
            'profile' => $user->only(['id', 'name', 'email', 'phone', 'status', 'preferred_currency', 'marketing_opt_in', 'notification_prefs', 'created_at', 'email_verified_at', 'phone_verified_at']),
            'roles' => $user->roles()->pluck('slug'),
            'orders' => Order::where('user_id', $user->id)->with('items')->get()->map(fn (Order $o) => [
                'reference' => $o->reference, 'status' => $o->status->value, 'total' => $o->total, 'currency' => $o->currency,
                'created_at' => $o->created_at?->toIso8601ZuluString(),
                'items' => $o->items->map(fn ($i) => ['name' => $i->name, 'quantity' => $i->quantity, 'unit_price' => $i->unit_price]),
            ]),
            'tickets' => Ticket::where('user_id', $user->id)->get(['id', 'event_id', 'ticket_type_id', 'status', 'checked_in_at', 'created_at']),
            'rentals' => Rental::where('user_id', $user->id)->get(['id', 'movie_id', 'status', 'activated_at', 'expires_at', 'created_at']),
            'devices' => DeviceToken::where('user_id', $user->id)->get(['platform', 'device_name', 'last_seen_at', 'created_at']),
            'sessions' => $user->tokens()->get(['id', 'name', 'last_used_at', 'created_at']),
        ];
    }

    /**
     * Delete the account: personal details are erased now; orders, payments, refunds and the audit log are kept
     * (tax and consumer-law record keeping) but no longer identify the person.
     */
    public function delete(User $user, string $password, ?string $reason, bool $forfeitTickets): void
    {
        $this->assertPassword($user, $password);

        if (Organizer::where('owner_id', $user->id)->whereIn('status', [Organizer::PENDING, Organizer::APPROVED])->exists()) {
            throw new ApiException(409, 'organizer_owner', 'You own an organizer account. Close or transfer it with our team before deleting your account.');
        }
        if (Refund::whereHas('order', fn ($q) => $q->where('user_id', $user->id))->whereIn('status', Refund::OPEN)->exists()) {
            throw new ApiException(409, 'refund_in_progress', 'A refund is still being processed. Please try again once it has finished.');
        }
        $upcoming = Ticket::where('user_id', $user->id)->where('status', Ticket::VALID)
            ->whereHas('event', fn ($q) => $q->where('starts_at', '>', now()))->count();
        $liveRentals = Rental::where('user_id', $user->id)->whereIn('status', [Rental::READY, Rental::ACTIVE])->get()
            ->filter(fn (Rental $r) => in_array($r->effectiveStatus(), [Rental::READY, Rental::ACTIVE], true))->count();
        if (($upcoming || $liveRentals) && ! $forfeitTickets) {
            throw new ApiException(409, 'has_active_purchases',
                "You still have {$upcoming} ticket(s) for upcoming events and {$liveRentals} active rental(s). Confirm you want to give them up, or request refunds first.",
                ['upcoming_tickets' => $upcoming, 'active_rentals' => $liveRentals]);
        }

        DB::transaction(function () use ($user, $reason, $upcoming, $liveRentals) {
            Ticket::where('user_id', $user->id)->where('status', Ticket::VALID)->update(['status' => Ticket::CANCELLED]);
            Rental::where('user_id', $user->id)->whereIn('status', [Rental::READY, Rental::ACTIVE])->update(['status' => Rental::REVOKED]);
            PlaybackProgress::where('user_id', $user->id)->delete();
            DeviceToken::where('user_id', $user->id)->delete();
            DB::table('verification_codes')->where('user_id', $user->id)->delete();
            $user->tokens()->delete();
            $user->roles()->detach();

            $user->forceFill([
                'name' => 'Deleted user',
                'email' => "deleted-{$user->id}-".Str::lower(Str::random(8)).'@deleted.invalid',
                'phone' => null,
                'password' => Str::random(64),
                'status' => User::STATUS_DELETED,
                'marketing_opt_in' => false,
                'notification_prefs' => null,
                'email_verified_at' => null,
                'phone_verified_at' => null,
                'remember_token' => null,
            ])->save();
            $user->delete(); // soft delete; financial records keep pointing at this anonymous row

            $this->audit->record('user.deleted', $user, ['reason' => $reason, 'forfeited_tickets' => $upcoming, 'forfeited_rentals' => $liveRentals]);
        });
    }

    private function assertPassword(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => ['Your password is not correct.']]);
        }
    }
}
