<?php

namespace App\Modules\Notifications\Messages;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;

/** Payment confirmation with the itemised receipt (email + inbox). */
class OrderReceipt extends AppNotification
{
    public function __construct(public readonly int $orderId)
    {
        parent::__construct();
    }

    private function order(): Order
    {
        return Order::with('items')->findOrFail($this->orderId);
    }

    public function type(): string
    {
        return 'order_paid';
    }

    public function title(User $user): string
    {
        return 'Receipt for order '.$this->order()->reference;
    }

    public function body(User $user): string
    {
        $o = $this->order();

        return 'Payment received: '.$o->currency.' '.Money::of((string) $o->total, $o->currency)->toDecimal().'. Thank you!';
    }

    public function data(): array
    {
        return ['order' => $this->order()->reference];
    }

    protected function usesPush(): bool
    {
        return false; // the tickets/rental-ready push follows separately
    }

    protected function emailPolicy(): string
    {
        return 'always';
    }

    public function toMail(User $user): MailMessage
    {
        $o = $this->order();
        $mail = (new MailMessage)->subject("Your receipt – order {$o->reference}")->greeting("Hi {$user->name},")
            ->line("Thanks for your purchase. Order **{$o->reference}**, paid ".$o->paid_at?->copy()->setTimezone(config('marketplace.display_timezone'))->format('j M Y, g:i a T').'.');
        foreach ($o->items as $item) {
            $mail->line("{$item->quantity} × {$item->name} — {$o->currency} {$item->line_total}");
        }
        if ((float) $o->fee_total > 0) {
            $mail->line("Booking fee — {$o->currency} {$o->fee_total}");
        }

        return $mail->line("**Total: {$o->currency} {$o->total}**")
            ->line('Your tickets and rentals are in the app.');
    }
}
