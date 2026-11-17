<?php

namespace App\Modules\Notifications\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Firebase Cloud Messaging HTTP v1. Authenticates with a service-account key (JSON file from the Firebase console):
 * a signed JWT is exchanged for a 1-hour OAuth token, cached for 50 minutes.
 */
class FcmPushSender implements PushSender
{
    /** @param array{credentials: string} $config path to the service-account JSON */
    public function __construct(private readonly array $config) {}

    public function send(string $token, string $title, string $body, array $data = []): string
    {
        $account = $this->account();
        $response = Http::withToken($this->accessToken($account))->acceptJson()->timeout(10)
            ->post("https://fcm.googleapis.com/v1/projects/{$account['project_id']}/messages:send", ['message' => [
                'token' => $token,
                'notification' => ['title' => $title, 'body' => $body],
                'data' => array_map('strval', $data),
                'android' => ['priority' => 'high'],
                'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
            ]]);

        if ($response->successful()) {
            return self::SENT;
        }
        $codes = collect($response->json('error.details', []))->pluck('errorCode')->filter()->all();
        if ($response->status() === 404 || in_array('UNREGISTERED', $codes, true)) {
            return self::INVALID_TOKEN;
        }
        Log::warning('FCM send failed', ['status' => $response->status(), 'error' => $response->json('error.message')]);

        return self::FAILED;
    }

    private function account(): array
    {
        $path = (string) ($this->config['credentials'] ?? '');
        $json = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($json) || empty($json['private_key']) || empty($json['client_email']) || empty($json['project_id'])) {
            throw new RuntimeException('FCM_CREDENTIALS must point to a Firebase service-account JSON file.');
        }

        return $json;
    }

    private function accessToken(array $account): string
    {
        return Cache::remember('fcm:access_token:'.md5($account['client_email']), now()->addMinutes(50), function () use ($account) {
            $now = time();
            $segments = [
                self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                self::b64(json_encode([
                    'iss' => $account['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'aud' => $account['token_uri'] ?? 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
                ])),
            ];
            if (! openssl_sign(implode('.', $segments), $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Could not sign the FCM token request.');
            }
            $jwt = implode('.', $segments).'.'.self::b64($signature);

            $response = Http::asForm()->timeout(10)->post($account['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt,
            ]);
            if (! $response->successful() || ! $response->json('access_token')) {
                throw new RuntimeException('FCM authentication failed: '.$response->status());
            }

            return (string) $response->json('access_token');
        });
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
