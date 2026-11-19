<?php

namespace App\Modules\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Http\Resources\EventResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Services\EventService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AdminEventController extends Controller
{
    public function __construct(private readonly EventService $events) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $v = $request->validate(['status' => ['nullable', Rule::enum(EventStatus::class)], 'search' => ['nullable', 'string', 'max:100']]);

        return EventResource::collection(Event::with(['venue', 'organizer', 'ticketTypes'])
            ->when($v['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($v['search'] ?? null, fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
            ->orderBy('starts_at')->paginate(25)->withQueryString());
    }

    public function show(Event $event): EventResource
    {
        return new EventResource($event->load(['venue', 'organizer', 'ticketTypes']));
    }

    public function review(Request $request, Event $event): EventResource
    {
        $v = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['required_if:decision,reject', 'nullable', 'string', 'max:500'],
        ]);

        return new EventResource($this->events->review($event, $v['decision'] === 'approve', $v['note'] ?? null, $request->user())
            ->load(['venue', 'organizer', 'ticketTypes']));
    }
}
