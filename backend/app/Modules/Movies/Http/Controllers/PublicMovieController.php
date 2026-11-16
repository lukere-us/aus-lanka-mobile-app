<?php

namespace App\Modules\Movies\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Movies\Http\Resources\MovieResource;
use App\Modules\Movies\Models\Movie;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Movie discovery (E02): published titles only, with their rental options. */
class PublicMovieController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'genre' => ['nullable', 'string', 'max:40'],
            'language' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return MovieResource::collection(Movie::published()
            ->with('packages')
            ->when($v['q'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('title', 'like', "%{$s}%")->orWhere('synopsis', 'like', "%{$s}%")))
            ->when($v['genre'] ?? null, fn ($q, $g) => $q->where('genres', 'like', '%"'.addcslashes($g, '%_"\\').'"%'))
            ->when($v['language'] ?? null, fn ($q, $l) => $q->where('languages', 'like', '%"'.addcslashes($l, '%_"\\').'"%'))
            ->orderByDesc('published_at')
            ->paginate($v['per_page'] ?? 20)->withQueryString());
    }

    public function show(string $slug): MovieResource
    {
        return new MovieResource(Movie::published()->where('slug', $slug)->with('packages')->firstOrFail());
    }
}
