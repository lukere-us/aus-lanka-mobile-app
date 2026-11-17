<?php

namespace App\Modules\Notifications\Channels;

use App\Models\User;
use App\Modules\Identity\Models\DeviceToken;
use App\Modules\Notifications\Push\PushSender;
use Illuminate\Notifications\Notification;

/** Delivers a notification's toPush() to every registered device of the user; forgets dead tokens. */
class PushChannel
{
    public function __construct(private readonly PushSender $sender) {}

    public function send(User $user, Notification $notification): void
    {
        if (($user->notification_prefs['push'] ?? true) === false) {
            return;
        }
        /** @var array{title: string, body: string, data?: array<string, string>} $message */
        $message = $notification->toPush($user);

        foreach (DeviceToken::where('user_id', $user->id)->get() as $device) {
            $result = $this->sender->send($device->token, $message['title'], $message['body'], $message['data'] ?? []);
            if ($result === PushSender::INVALID_TOKEN) {
                $device->delete();
            } elseif ($result === PushSender::SENT) {
                $device->forceFill(['last_seen_at' => now()])->save();
            }
        }
    }
}
