<?php

namespace App\Modules\Notifications\Messages;

use App\Models\User;
use App\Modules\Notifications\Channels\PushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base for customer notifications: in-app inbox + push + (optionally) email, queued on "notifications" and sent
 * after the surrounding transaction commits. Reminders respect the user's email_reminders preference;
 * transactional messages (receipts, refunds, cancellations) always email. Deleted accounts get nothing.
 */
abstract class AppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** Short type key used by the app to route a tap, e.g. "tickets_ready". */
    abstract public function type(): string;

    abstract public function title(User $user): string;

    abstract public function body(User $user): string;

    /** @return array<string, string> deep-link data (order reference, ticket id, ...) */
    public function data(): array
    {
        return [];
    }

    /** Whether this notification sends email: 'always', 'reminder' (if the user allows) or 'never'. */
    protected function emailPolicy(): string
    {
        return 'never';
    }

    protected function usesPush(): bool
    {
        return true;
    }

    public function via(User $user): array
    {
        if ($user->status === User::STATUS_DELETED) {
            return [];
        }
        $channels = ['database'];
        if ($this->usesPush()) {
            $channels[] = PushChannel::class;
        }
        $policy = $this->emailPolicy();
        if ($policy === 'always' || ($policy === 'reminder' && ($user->notification_prefs['email_reminders'] ?? true) !== false)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toArray(User $user): array
    {
        return ['type' => $this->type(), 'title' => $this->title($user), 'body' => $this->body($user), 'data' => $this->data()];
    }

    public function toPush(User $user): array
    {
        return ['title' => $this->title($user), 'body' => $this->body($user), 'data' => ['type' => $this->type()] + $this->data()];
    }

    public function toMail(User $user): MailMessage
    {
        return (new MailMessage)->subject($this->title($user))->greeting("Hi {$user->name},")->line($this->body($user));
    }
}
