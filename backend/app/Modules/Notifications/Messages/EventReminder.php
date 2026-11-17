<?php

namespace App\Modules\Notifications\Messages;

use App\Models\User;
use App\Modules\Events\Models\Event;

class EventReminder extends AppNotification
{
    public function __construct(public readonly int $eventId)
    {
        parent::__construct();
    }

    private function event(): Event
    {
        return Event::with('venue')->findOrFail($this->eventId);
    }

    public function type(): string
    {
        return 'event_reminder';
    }

    public function title(User $user): string
    {
        return $this->event()->title.' is tomorrow';
    }

    public function body(User $user): string
    {
        $e = $this->event();
        $local = $e->starts_at->copy()->setTimezone($e->venue->timezone);

        return "Starts {$local->format('D j M, g:i a T')} at {$e->venue->name}. Have your QR ticket ready in the app.";
    }

    public function data(): array
    {
        return ['event' => $this->event()->slug];
    }

    protected function emailPolicy(): string
    {
        return 'reminder';
    }
}
