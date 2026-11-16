<?php

namespace App\Modules\Rentals\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiException;
use App\Modules\Movies\Models\MovieAsset;
use App\Modules\Rentals\Video\SampleVideoProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Local-only: the "CDN" side of the sample provider, plus a tiny browser player for testing. */
class DevStreamController extends Controller
{
    public function stream(string $token): RedirectResponse
    {
        $claims = SampleVideoProvider::verify($token);
        if (! $claims) {
            throw new ApiException(403, 'link_expired', 'This playback link is invalid or has expired.');
        }
        $asset = MovieAsset::whereKey($claims['a'])->where('provider', 'sample')->firstOrFail();

        return redirect()->away($asset->provider_asset_id);
    }

    public function player(Request $request): Response
    {
        $src = (string) $request->query('src', '');
        abort_unless(str_starts_with($src, url('/api/v1/dev/stream/')), 404);
        $srcJson = json_encode($src, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $html = <<<HTML
<!doctype html><html><head><meta charset="utf-8"><title>Rental test player</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>body{margin:0;background:#111;color:#ddd;font:14px system-ui,sans-serif}video{width:100%;max-height:85vh;background:#000}p{padding:8px 16px}</style>
</head><body>
<video id="v" controls autoplay playsinline></video>
<p id="m">Loading the rented stream through a short-lived signed link…</p>
<script src="https://cdn.jsdelivr.net/npm/hls.js@1/dist/hls.min.js"></script>
<script>
const src = {$srcJson}, v = document.getElementById('v'), m = document.getElementById('m');
if (v.canPlayType('application/vnd.apple.mpegurl')) { v.src = src; }
else if (window.Hls && Hls.isSupported()) { const h = new Hls(); h.loadSource(src); h.attachMedia(v);
  h.on(Hls.Events.ERROR, (e, d) => { if (d.fatal) m.textContent = 'Playback error: ' + d.details + ' (the link may have expired; press Play in the app again).'; }); }
else { m.textContent = 'This browser cannot play HLS.'; }
v.addEventListener('playing', () => m.textContent = 'Playing. This link stops working when it expires or the rental ends.');
</script></body></html>
HTML;

        return response($html)->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Content-Security-Policy', "default-src 'none'; script-src 'unsafe-inline' https://cdn.jsdelivr.net; media-src * blob:; connect-src *; style-src 'unsafe-inline'; worker-src blob:");
    }
}
