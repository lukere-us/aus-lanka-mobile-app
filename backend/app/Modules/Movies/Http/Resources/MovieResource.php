<?php

namespace App\Modules\Movies\Http\Resources;

use App\Modules\Movies\Models\Movie;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Movie */
class MovieResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = $request->is('api/v1/admin/*');

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'synopsis' => $this->synopsis,
            'release_year' => $this->release_year,
            'runtime_minutes' => $this->runtime_minutes,
            'classification' => $this->classification,
            'genres' => $this->genres ?? [],
            'languages' => $this->languages ?? [],
            'poster_url' => $this->poster_url,
            'backdrop_url' => $this->backdrop_url,
            'trailer_url' => $this->trailer_url,
            'rental_packages' => $this->whenLoaded('packages', fn () => $this->packages
                ->when(! $admin, fn ($c) => $c->where('status', 'active'))
                ->map->toApi()->values()),
            'status' => $this->when($admin, $this->status),
            'assets' => $this->when($admin && $this->relationLoaded('assets'), fn () => $this->assets->map(fn ($a) => [
                'id' => $a->id, 'kind' => $a->kind, 'provider' => $a->provider, 'status' => $a->status,
                'duration_seconds' => $a->duration_seconds, 'subtitles' => $a->subtitles ?? [],
            ])->values()),
            'published_at' => $this->when($admin, fn () => $this->published_at?->toIso8601ZuluString()),
        ];
    }
}
