<?php

use App\Modules\Rentals\Video\SampleVideoProvider;

return [
    /*
    | Video hosting provider. "sample" plays public test streams through our own short-lived signed links,
    | so the whole rent -> pay -> watch flow can be tested before the client picks Bunny Stream or Cloudflare
    | Stream (decision log). A real provider = one class implementing VideoProvider + an entry here.
    */
    'provider' => env('VIDEO_PROVIDER', 'sample'),

    // Lifetime of a playback link. Short: the app asks for a fresh one each time playback starts.
    'link_ttl_minutes' => (int) env('VIDEO_LINK_TTL_MINUTES', 10),

    'providers' => [
        'sample' => [
            'class' => SampleVideoProvider::class,
        ],
    ],

    // Big Buck Bunny (c) Blender Foundation, CC BY 3.0 - public HLS test stream.
    'sample_stream_url' => env('SAMPLE_STREAM_URL', 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8'),
];
