<?php

namespace App\Modules\Notifications\Console;

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Notifications\Messages\EventReminder;
use App\Modules\Notifications\Messages\RentalExpiringSoon;
use App\Modules\Rentals\Models\Rental;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** "Your rental ends soon" and "your event is tomorrow" reminders. Each is sent once. Runs every 15 minutes. */
class SendReminders extends Command
{
    protected $signature = 'notifications:reminders';

    protected $description = 'Send rental-expiry and event reminders that are due.';

    public function handle(): int
    {
        $rentals = 0;
        Rental::where('status', Rental::ACTIVE)->whereNull('expiry_reminded_at')
            ->whereBetween('expires_at', [now(), now()->addHours(config('notifications.rental_expiry_warning_hours'))])
            ->with(['movie', 'user'])->chunkById(200, function ($batch) use (&$rentals) {
                foreach ($batch as $rental) {
                    // Claim first, so an overlapping run never sends twice.
                    if (Rental::whereKey($rental->id)->whereNull('expiry_reminded_at')->update(['expiry_reminded_at' => now()]) === 1) {
                        $rental->user->notify(new RentalExpiringSoon($rental->id, $rental->movie->title, max(1, (int) ceil(now()->diffInMinutes($rental->expires_at) / 60))));
                        $rentals++;
                    }
                }
            });

        $events = 0;
        $due = Event::where('status', EventStatus::Published)
            ->whereBetween('starts_at', [now()->addHour(), now()->addHours(config('notifications.event_reminder_hours'))])->pluck('id');
        foreach ($due as $eventId) {
            $holders = Ticket::where('event_id', $eventId)->where('status', Ticket::VALID)->distinct()->pluck('user_id');
            foreach ($holders as $userId) {
                $inserted = DB::table('event_reminders')->insertOrIgnore(['user_id' => $userId, 'event_id' => $eventId, 'sent_at' => now()]);
                if ($inserted === 1 && ($user = User::find($userId))) {
                    $user->notify(new EventReminder($eventId));
                    $events++;
                }
            }
        }

        $this->info("Sent {$rentals} rental and {$events} event reminder(s).");

        return self::SUCCESS;
    }
}
