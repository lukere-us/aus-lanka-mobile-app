<?php

namespace App\Modules\Notifications\Push;

use Illuminate\Support\Facades\Log;

class LogPushSender implements PushSender
{
    public function send(string $token, string $title, string $body, array $data = []): string
    {
        Log::info('Push (log driver)', ['token' => substr($token, 0, 12).'…', 'title' => $title, 'body' => $body, 'data' => $data]);

        return self::SENT;
    }
}
