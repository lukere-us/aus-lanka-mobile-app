<?php

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerificationCodeNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $code, public readonly int $minutes) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your verification code: {$this->code}")
            ->greeting('Hello '.$notifiable->name.',')
            ->line("Your verification code is **{$this->code}**.")
            ->line("It expires in {$this->minutes} minutes. If you did not ask for this, you can ignore this email.");
    }
}
