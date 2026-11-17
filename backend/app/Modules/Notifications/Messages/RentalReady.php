<?php

namespace App\Modules\Notifications\Messages;

use App\Models\User;

class RentalReady extends AppNotification
{
    public function __construct(public readonly int $rentalId, public readonly string $movieTitle, public readonly int $watchHours, public readonly int $startWithinDays)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return 'rental_ready';
    }

    public function title(User $user): string
    {
        return "{$this->movieTitle} is ready to watch";
    }

    public function body(User $user): string
    {
        return "Start within {$this->startWithinDays} days. Once you press play you have {$this->watchHours} hours to finish.";
    }

    public function data(): array
    {
        return ['rental' => (string) $this->rentalId];
    }
}
