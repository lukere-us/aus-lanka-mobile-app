<?php

namespace App\Modules\Rentals\Video;

use App\Models\User;
use App\Modules\Movies\Models\MovieAsset;
use DateTimeInterface;

/**
 * Hosting/CDN adapter (Bunny Stream, Cloudflare Stream, ... or the sample provider). Only ever called after
 * our server has checked the viewer's rental; it returns a link that stops working at $expiresAt.
 */
interface VideoProvider
{
    public function name(): string;

    /** HLS manifest URL for this viewer, valid until $expiresAt. */
    public function playbackUrl(MovieAsset $asset, User $viewer, DateTimeInterface $expiresAt): string;
}
