<?php

namespace App\Modules\Identity\Sms;

/** SMS provider adapter (Twilio, MessageMedia, AWS SNS, ... once chosen; decision log). */
interface SmsSender
{
    public function send(string $e164Number, string $message): void;
}
