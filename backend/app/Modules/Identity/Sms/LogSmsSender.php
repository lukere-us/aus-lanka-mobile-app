<?php

namespace App\Modules\Identity\Sms;

use Illuminate\Support\Facades\Log;

/** Stand-in until an SMS provider is chosen: writes the message to the application log (storage/logs). */
class LogSmsSender implements SmsSender
{
    public function send(string $e164Number, string $message): void
    {
        Log::info('SMS (log driver)', ['to' => $e164Number, 'message' => $message]);
    }
}
