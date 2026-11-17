<?php

namespace App\Modules\Notifications\Messages;

use App\Models\User;

class EventCancelled extends AppNotification
{
    public function __construct(public readonly int $eventId, public readonly string $eventTitle, public readonly string $reason)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return 'event_cancelled';
    }

    public function title(User $user): string
    {
        return "Cancelled: {$this->eventTitle}";
    }

    public function body(User $user): string
    {
        return "Sorry, this event has been cancelled ({$this->reason}). A full refund, including booking fees, is on its way to your original payment method.";
    }

    public function data(): array
    {
        return ['event_id' => (string) $this->eventId];
    }

    protected function emailPolicy(): string
    {
        return 'always';
    }
}
