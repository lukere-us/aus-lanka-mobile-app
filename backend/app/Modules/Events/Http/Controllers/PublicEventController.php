<?php

namespace App\Modules\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Events\Http\Requests\EventRequest;
use App\Modules\Events\Http\Resources\EventResource;
use App\Modules\Events\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Public event discovery (F02): only published, upcoming events of approved organizers. */
class PublicEventController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'in:'.implode(',', EventRequest::CATEGORIES)],
            'city' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'size:2'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return EventResource::collection(Event::onSale()
            ->with(['venue', 'organizer', 'ticketTypes'])
            ->when($v['q'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('title', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")))
            ->when($v['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($v['city'] ?? null, fn ($q, $c) => $q->whereHas('venue', fn ($vq) => $vq->where('city', $c)))
            ->when($v['country'] ?? null, fn ($q, $c) => $q->whereHas('venue', fn ($vq) => $vq->where('country', strtoupper($c))))
            ->when($v['from'] ?? null, fn ($q, $d) => $q->where('starts_at', '>=', $d))
            ->when($v['to'] ?? null, fn ($q, $d) => $q->where('starts_at', '<=', $d))
            ->orderBy('starts_at')
            ->paginate($v['per_page'] ?? 20)->withQueryString());
    }

    public function show(string $slug): EventResource
    {
        return new EventResource(Event::onSale()->where('slug', $slug)->with(['venue', 'organizer', 'ticketTypes'])->firstOrFail());
    }
}
