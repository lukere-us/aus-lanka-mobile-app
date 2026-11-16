<?php

namespace App\Modules\Rentals\Video;

use App\Models\User;
use App\Modules\Movies\Models\MovieAsset;
use DateTimeInterface;

/**
 * Test stand-in for a video CDN. Issues our own signed, expiring link (/api/v1/dev/stream/{token}); the dev
 * endpoint checks signature + expiry and redirects to the public sample stream. With Bunny or Cloudflare the
 * CDN itself checks the signed token, so the video file is never reachable without one.
 */
class SampleVideoProvider implements VideoProvider
{
    public function name(): string
    {
        return 'sample';
    }

    public function playbackUrl(MovieAsset $asset, User $viewer, DateTimeInterface $expiresAt): string
    {
        return url('/api/v1/dev/stream/'.self::sign(['a' => $asset->id, 'u' => $viewer->id, 'exp' => $expiresAt->getTimestamp()]));
    }

    public static function sign(array $claims): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');

        return $payload.'.'.hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    /** @return array<string, int>|null claims when the token is genuine and unexpired */
    public static function verify(string $token): ?array
    {
        [$payload, $mac] = array_pad(explode('.', $token, 2), 2, '');
        if ($mac === '' || ! hash_equals(hash_hmac('sha256', $payload, (string) config('app.key')), $mac)) {
            return null;
        }
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);

        return is_array($claims) && ($claims['exp'] ?? 0) >= now()->getTimestamp() ? $claims : null;
    }
}
