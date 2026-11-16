<?php

namespace Database\Seeders;

use App\Modules\Movies\Models\Movie;
use Illuminate\Database\Seeder;

/** Local demo catalogue: one open-licensed film on the sample video provider. Idempotent; never runs in production. */
class DemoContentSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $movie = Movie::firstOrCreate(['slug' => 'big-buck-bunny-demo'], [
            'title' => 'Big Buck Bunny (demo)',
            'synopsis' => 'Open-licensed animated short by the Blender Foundation (CC BY 3.0), used to test rentals and playback.',
            'release_year' => 2008,
            'runtime_minutes' => 10,
            'classification' => 'G',
            'genres' => ['Animation', 'Comedy'],
            'languages' => ['English'],
            'status' => Movie::PUBLISHED,
            'published_at' => now(),
        ]);

        if (! $movie->assets()->exists()) {
            $movie->assets()->create([
                'kind' => 'feature', 'provider' => 'sample', 'status' => 'ready', 'duration_seconds' => 596,
                'provider_asset_id' => config('video.sample_stream_url'),
            ]);
        }
        if (! $movie->packages()->exists()) {
            $movie->packages()->create([
                'name' => '48-hour rental', 'quality' => 'HD', 'price' => '4.99', 'currency' => config('marketplace.currency'),
                'watch_hours' => 48, 'start_within_days' => 30, 'status' => 'active',
            ]);
        }
    }
}
