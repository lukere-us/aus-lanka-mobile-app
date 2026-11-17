<?php

namespace App\Modules\Notifications\Messages;

use App\Models\User;

class RentalExpiringSoon extends AppNotification
{
    public function __construct(public readonly int $rentalId, public readonly string $movieTitle, public readonly int $hoursLeft)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return 'rental_expiring';
    }

    public function title(User $user): string
    {
        return "{$this->movieTitle}: rental ends soon";
    }

    public function body(User $user): string
    {
        return "Your rental ends in about {$this->hoursLeft} hour".($this->hoursLeft === 1 ? '' : 's').'. Finish watching before then.';
    }

    public function data(): array
    {
        return ['rental' => (string) $this->rentalId];
    }

    protected function emailPolicy(): string
    {
        return 'reminder';
    }
}
