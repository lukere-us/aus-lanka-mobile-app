<?php

namespace App\Modules\Notifications\Messages;

use App\Models\User;
use App\Modules\Refunds\Models\Refund;

class RefundCompleted extends AppNotification
{
    public function __construct(public readonly int $refundId)
    {
        parent::__construct();
    }

    private function refund(): Refund
    {
        return Refund::with('order')->findOrFail($this->refundId);
    }

    public function type(): string
    {
        return 'refund_completed';
    }

    public function title(User $user): string
    {
        return 'Refund processed';
    }

    public function body(User $user): string
    {
        $r = $this->refund();

        return "We refunded {$r->currency} {$r->amount} for order {$r->order->reference}. Banks usually show it within 5–10 business days.";
    }

    public function data(): array
    {
        return ['order' => $this->refund()->order->reference];
    }

    protected function emailPolicy(): string
    {
        return 'always';
    }
}
