<?php

namespace App\Modules\Notifications\Messages;

use App\Models\User;
use App\Modules\Events\Models\Event;

/** The date, time or venue of an event someone holds tickets for has changed. */
class EventChanged extends AppNotification
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
        return 'event_changed';
    }

    public function title(User $user): string
    {
        return 'Update: '.$this->event()->title;
    }

    public function body(User $user): string
    {
        $e = $this->event();
        $local = $e->starts_at->copy()->setTimezone($e->venue->timezone);

        return "The event details changed. It now starts {$local->format('D j M Y, g:i a T')} at {$e->venue->name}. Your tickets stay valid.";
    }

    public function data(): array
    {
        return ['event' => $this->event()->slug];
    }

    protected function emailPolicy(): string
    {
        return 'always';
    }
}
