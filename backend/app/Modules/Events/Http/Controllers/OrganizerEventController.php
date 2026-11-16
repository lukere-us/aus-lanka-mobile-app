<?php

namespace App\Modules\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Events\Http\Requests\EventRequest;
use App\Modules\Events\Http\Requests\TicketTypeRequest;
use App\Modules\Events\Http\Resources\EventResource;
use App\Modules\Events\Http\Resources\TicketTypeResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\TicketType;
use App\Modules\Events\Models\Venue;
use App\Modules\Events\Services\EventService;
use App\Modules\Organizers\Services\OrganizerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Organizer portal APIs (F01, F03, F09). Every query is scoped to the caller's own organizer. */
class OrganizerEventController extends Controller
{
    public function __construct(private readonly EventService $events, private readonly OrganizerContext $context) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $organizer = $this->context->for($request->user());

        return EventResource::collection(Event::where('organizer_id', $organizer->id)
            ->with(['venue', 'ticketTypes'])->orderByDesc('starts_at')->paginate(25));
    }

    public function store(EventRequest $request): JsonResponse
    {
        $event = $this->events->create($this->context->for($request->user()), $request->validated());

        return (new EventResource($event->load(['venue', 'ticketTypes'])))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $event): EventResource
    {
        return new EventResource($this->own($request, $event)->load(['venue', 'ticketTypes']));
    }

    public function update(EventRequest $request, int $event): EventResource
    {
        return new EventResource($this->events->update($this->own($request, $event), $request->validated())->load(['venue', 'ticketTypes']));
    }

    public function submit(Request $request, int $event): EventResource
    {
        return new EventResource($this->events->submit($this->own($request, $event))->load(['venue', 'ticketTypes']));
    }

    public function storeTicketType(TicketTypeRequest $request, int $event): JsonResponse
    {
        $type = $this->events->addTicketType($this->own($request, $event), $request->validated());

        return (new TicketTypeResource($type))->response()->setStatusCode(201);
    }

    public function updateTicketType(TicketTypeRequest $request, int $event, int $ticketType): TicketTypeResource
    {
        $type = TicketType::where('event_id', $this->own($request, $event)->id)->findOrFail($ticketType);

        return new TicketTypeResource($this->events->updateTicketType($type, $request->validated()));
    }

    public function storeVenue(Request $request): JsonResponse
    {
        $organizer = $this->context->for($request->user());
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:80'],
            'country' => ['required', 'string', 'size:2', 'alpha'],
            'timezone' => ['required', 'timezone:all'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'capacity' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json(['data' => Venue::create(['country' => strtoupper($data['country'])] + $data + ['organizer_id' => $organizer->id])], 201);
    }

    public function venues(Request $request): JsonResponse
    {
        $organizer = $this->context->for($request->user());

        return response()->json(['data' => Venue::whereNull('organizer_id')->orWhere('organizer_id', $organizer->id)
            ->orderBy('city')->orderBy('name')->get()]);
    }

    private function own(Request $request, int $eventId): Event
    {
        return Event::where('organizer_id', $this->context->for($request->user())->id)->findOrFail($eventId);
    }
}
