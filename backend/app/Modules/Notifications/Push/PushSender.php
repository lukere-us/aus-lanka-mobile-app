<?php

namespace App\Modules\Notifications\Push;

/** Push provider adapter (Firebase Cloud Messaging; a log stand-in until Firebase is set up). */
interface PushSender
{
    public const SENT = 'sent';

    public const INVALID_TOKEN = 'invalid_token'; // app uninstalled / token expired: forget it

    public const FAILED = 'failed';

    /** @param array<string, string> $data deep-link data for the app */
    public function send(string $token, string $title, string $body, array $data = []): string;
}
