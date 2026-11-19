<?php

namespace App\Modules\Movies\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiException;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Movies\Http\Resources\MovieResource;
use App\Modules\Movies\Models\Movie;
use App\Modules\Movies\Models\RentalPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Content management (E01, E03, E10 API). Requires movies.manage. */
class AdminMovieController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $v = $request->validate(['status' => ['nullable', Rule::in([Movie::DRAFT, Movie::PUBLISHED, Movie::UNPUBLISHED])]]);

        return MovieResource::collection(Movie::with(['packages', 'assets'])
            ->when($v['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest('id')->paginate(25)->withQueryString());
    }

    public function show(Movie $movie): MovieResource
    {
        return new MovieResource($movie->load(['packages', 'assets']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->movieRules(true));
        $movie = Movie::create($data + ['slug' => Str::limit(Str::slug($data['title']), 100, '').'-'.Str::lower(Str::random(5)), 'status' => Movie::DRAFT]);
        $this->audit->record('movie.created', $movie);

        return (new MovieResource($movie->load(['packages', 'assets'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, Movie $movie): MovieResource
    {
        $data = $request->validate($this->movieRules(false) + ['status' => ['sometimes', Rule::in([Movie::DRAFT, Movie::PUBLISHED, Movie::UNPUBLISHED])]]);

        if (($data['status'] ?? null) === Movie::PUBLISHED) {
            if (! $movie->feature()->exists()) {
                throw new ApiException(422, 'no_video', 'Add a ready video before publishing.');
            }
            if (! $movie->packages()->where('status', 'active')->exists()) {
                throw new ApiException(422, 'no_rental_package', 'Add at least one active rental package before publishing.');
            }
            $data['published_at'] = $movie->published_at ?? now();
        }

        $before = $movie->only(array_keys($data));
        $movie->update($data);
        $this->audit->record(isset($data['status']) ? 'movie.status_changed' : 'movie.updated', $movie, ['before' => $before, 'after' => $movie->only(array_keys($data))]);

        return new MovieResource($movie->load(['packages', 'assets']));
    }

    public function addAsset(Request $request, Movie $movie): MovieResource
    {
        $data = $request->validate([
            'kind' => ['required', 'in:feature,trailer'],
            'provider' => ['required', Rule::in(array_keys(config('video.providers')))],
            'provider_asset_id' => ['required', 'string', 'max:500'],
            'duration_seconds' => ['nullable', 'integer', 'min:1'],
            'subtitles' => ['nullable', 'array'],
            'subtitles.*.lang' => ['required', 'string', 'max:10'],
            'subtitles.*.label' => ['required', 'string', 'max:40'],
            'subtitles.*.url' => ['required', 'url'],
        ]);
        $asset = $movie->assets()->create($data + ['status' => 'ready']);
        $this->audit->record('movie.asset_added', $movie, ['asset_id' => $asset->id, 'provider' => $asset->provider]);

        return new MovieResource($movie->load(['packages', 'assets']));
    }

    public function addPackage(Request $request, Movie $movie): JsonResponse
    {
        $data = $request->validate($this->packageRules(true));
        $package = $movie->packages()->create($data + ['currency' => config('marketplace.currency')]);
        $this->audit->record('rental_package.created', $package, $package->toApi());

        return response()->json(['data' => $package->toApi()], 201);
    }

    public function updatePackage(Request $request, Movie $movie, RentalPackage $package): JsonResponse
    {
        abort_unless($package->movie_id === $movie->id, 404);
        $data = $request->validate($this->packageRules(false));
        $before = $package->toApi();
        $package->update($data);
        $this->audit->record('rental_package.updated', $package, ['before' => $before, 'after' => $package->toApi()]);

        return response()->json(['data' => $package->toApi()]);
    }

    private function movieRules(bool $creating): array
    {
        $r = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$r, 'string', 'max:150'],
            'synopsis' => ['nullable', 'string', 'max:5000'],
            'release_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'runtime_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'classification' => ['nullable', 'string', 'max:10'],
            'genres' => ['nullable', 'array', 'max:6'],
            'genres.*' => ['string', 'max:40'],
            'languages' => ['nullable', 'array', 'max:6'],
            'languages.*' => ['string', 'max:40'],
            'poster_url' => ['nullable', 'url', 'max:500'],
            'backdrop_url' => ['nullable', 'url', 'max:500'],
            'trailer_url' => ['nullable', 'url', 'max:500'],
        ];
    }

    private function packageRules(bool $creating): array
    {
        $r = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$r, 'string', 'max:80'],
            'quality' => ['sometimes', 'in:SD,HD,4K'],
            'price' => [$r, 'decimal:0,2', 'min:0.5', 'max:999.99'],
            'watch_hours' => [$r, 'integer', 'min:1', 'max:720'],
            'start_within_days' => [$r, 'integer', 'min:1', 'max:90'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
