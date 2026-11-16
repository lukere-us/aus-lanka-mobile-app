<?php

namespace App\Modules\Rentals\Services;

use App\Http\Responses\ApiException;
use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Rentals\Models\PlaybackProgress;
use App\Modules\Rentals\Models\Rental;
use App\Modules\Rentals\Video\VideoProviderManager;
use Illuminate\Support\Facades\DB;

/**
 * Secure playback authorization (E05, E07). Every play request re-checks ownership, account status and the
 * rental window on the server, then hands out a short-lived link. No permanent video URL is ever exposed.
 */
class PlaybackService
{
    public function __construct(private readonly VideoProviderManager $video, private readonly AuditLogger $audit) {}

    /** @return array<string, mixed> */
    public function authorize(User $viewer, int $rentalId): array
    {
        if (! $viewer->isActive()) {
            throw new ApiException(403, 'account_suspended', 'This account is suspended.');
        }

        $rental = DB::transaction(function () use ($viewer, $rentalId) {
            $rental = Rental::where('user_id', $viewer->id)->whereKey($rentalId)->lockForUpdate()->firstOrFail();

            $status = $rental->effectiveStatus();
            if ($status === Rental::EXPIRED || $status === Rental::REVOKED) {
                // Record the expiry and return; throwing here would roll the update back.
                if ($rental->status !== $status) {
                    $rental->update(['status' => $status]);
                }

                return $rental;
            }

            if ($rental->status === Rental::READY) {
                // First play starts the viewing window.
                $rental->update(['status' => Rental::ACTIVE, 'activated_at' => now(), 'expires_at' => now()->addHours($rental->watch_hours)]);
                $this->audit->record('rental.activated', $rental, ['expires_at' => $rental->expires_at->toIso8601ZuluString()]);
            }

            return $rental;
        });

        if (in_array($rental->status, [Rental::EXPIRED, Rental::REVOKED], true)) {
            throw new ApiException(403, 'rental_expired', 'This rental has ended. Rent it again to keep watching.');
        }

        $asset = $rental->movie->feature;
        if (! $asset) {
            throw new ApiException(503, 'video_unavailable', 'This movie is temporarily unavailable. Please try again later.');
        }

        // The link never outlives the rental itself.
        $linkExpires = min(now()->addMinutes(config('video.link_ttl_minutes'))->getTimestamp(), $rental->expires_at->getTimestamp());
        $expiresAt = now()->setTimestamp($linkExpires);
        $progress = PlaybackProgress::where('user_id', $viewer->id)->where('movie_id', $rental->movie_id)->first();

        return [
            'manifest_url' => $this->video->driver($asset->provider)->playbackUrl($asset, $viewer, $expiresAt),
            'format' => 'hls',
            'link_expires_at' => $expiresAt->toIso8601ZuluString(),
            'rental_expires_at' => $rental->expires_at->toIso8601ZuluString(),
            'resume_position_seconds' => $progress && ! $progress->completed ? $progress->position_seconds : 0,
            'subtitles' => $asset->subtitles ?? [],
        ];
    }

    public function saveProgress(User $viewer, int $rentalId, int $position, bool $completed): PlaybackProgress
    {
        $rental = Rental::where('user_id', $viewer->id)->findOrFail($rentalId);

        return PlaybackProgress::updateOrCreate(
            ['user_id' => $viewer->id, 'movie_id' => $rental->movie_id],
            ['position_seconds' => max(0, $position), 'completed' => $completed],
        );
    }
}
