<?php

namespace App\Modules\Rentals\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Rentals\Models\PlaybackProgress;
use App\Modules\Rentals\Models\Rental;
use App\Modules\Rentals\Services\PlaybackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Customer rental library and playback (E05, E07, E08 / H04-H05 API). */
class RentalController extends Controller
{
    public function __construct(private readonly PlaybackService $playback) {}

    public function index(Request $request): JsonResponse
    {
        $rentals = Rental::where('user_id', $request->user()->id)->with(['movie', 'package'])->latest('id')->get();
        $progress = PlaybackProgress::where('user_id', $request->user()->id)->pluck('position_seconds', 'movie_id');

        return response()->json(['data' => $rentals->map(fn (Rental $r) => $this->present($r, $progress[$r->movie_id] ?? 0))->values()]);
    }

    public function show(Request $request, int $rental): JsonResponse
    {
        $r = Rental::where('user_id', $request->user()->id)->with(['movie', 'package'])->findOrFail($rental);
        $position = PlaybackProgress::where('user_id', $r->user_id)->where('movie_id', $r->movie_id)->value('position_seconds') ?? 0;

        return response()->json(['data' => $this->present($r, $position)]);
    }

    public function play(Request $request, int $rental): JsonResponse
    {
        $data = $this->playback->authorize($request->user(), $rental);
        if (! app()->isProduction() && config('video.provider') === 'sample') {
            $data['test_player_url'] = url('/api/v1/dev/player?src='.urlencode($data['manifest_url']));
        }

        return response()->json(['data' => $data]);
    }

    public function progress(Request $request, int $rental): JsonResponse
    {
        $v = $request->validate(['position_seconds' => ['required', 'integer', 'min:0', 'max:86400'], 'completed' => ['sometimes', 'boolean']]);
        $p = $this->playback->saveProgress($request->user(), $rental, $v['position_seconds'], (bool) ($v['completed'] ?? false));

        return response()->json(['data' => ['position_seconds' => $p->position_seconds, 'completed' => $p->completed]]);
    }

    private function present(Rental $r, int $position): array
    {
        return [
            'id' => $r->id,
            'status' => $r->effectiveStatus(),
            'movie' => ['id' => $r->movie->id, 'slug' => $r->movie->slug, 'title' => $r->movie->title, 'poster_url' => $r->movie->poster_url],
            'package' => ['name' => $r->package->name, 'quality' => $r->package->quality, 'watch_hours' => $r->watch_hours],
            'start_by' => $r->activate_by->toIso8601ZuluString(),
            'activated_at' => $r->activated_at?->toIso8601ZuluString(),
            'expires_at' => $r->expires_at?->toIso8601ZuluString(),
            'resume_position_seconds' => $position,
        ];
    }
}
