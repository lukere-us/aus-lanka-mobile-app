<?php

namespace App\Modules\Notifications\Messages;

use App\Models\User;
use App\Modules\Orders\Models\Order;

class TicketsReady extends AppNotification
{
    public function __construct(public readonly int $orderId, public readonly int $count, public readonly string $eventTitle)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return 'tickets_ready';
    }

    public function title(User $user): string
    {
        return 'Your tickets are ready';
    }

    public function body(User $user): string
    {
        return "{$this->count} ticket".($this->count === 1 ? '' : 's')." for {$this->eventTitle} ".($this->count === 1 ? 'is' : 'are').' in your wallet.';
    }

    public function data(): array
    {
        return ['order' => (string) Order::whereKey($this->orderId)->value('reference')];
    }
}
