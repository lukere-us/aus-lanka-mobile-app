<?php

namespace App\Modules\EventOps\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiException;
use App\Models\User;
use App\Modules\EventOps\Models\EventGate;
use App\Modules\EventOps\Models\EventStaff;
use App\Modules\EventOps\Models\ScannerDevice;
use App\Modules\EventOps\Services\EventOpsService;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Organizers\Models\Organizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Scanner app support (G01): which events I can scan, device registration, gate stats. */
class ScannerController extends Controller
{
    public function __construct(private readonly EventOpsService $ops) {}

    public function events(Request $request): JsonResponse
    {
        $user = $request->user();
        $events = Event::whereIn('id', $this->scannableEventIds($user))
            ->where('status', EventStatus::Published)
            ->whereBetween('starts_at', [now()->subHours(18), now()->addDays(7)])
            ->with('venue')->orderBy('starts_at')->get();
        $assignments = EventStaff::where('user_id', $user->id)->whereIn('event_id', $events->pluck('id'))->pluck('gate_id', 'event_id');
        $gates = EventGate::whereIn('event_id', $events->pluck('id'))->get()->groupBy('event_id');

        return response()->json(['data' => $events->map(fn (Event $e) => [
            'id' => $e->id, 'title' => $e->title, 'starts_at' => $e->starts_at->toIso8601ZuluString(),
            'starts_at_local' => $e->starts_at->copy()->setTimezone($e->venue->timezone)->toIso8601String(),
            'venue' => $e->venue->name,
            'gates' => ($gates[$e->id] ?? collect())->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->values(),
            'my_gate_id' => $assignments[$e->id] ?? null,
        ])]);
    }

    public function registerDevice(Request $request): JsonResponse
    {
        $v = $request->validate(['device_uid' => ['required', 'string', 'max:100'], 'name' => ['required', 'string', 'max:100'], 'organizer_id' => ['nullable', 'integer']]);
        $user = $request->user();
        $org = Organizer::whereHas('members', fn ($q) => $q->where('users.id', $user->id))
            ->when($v['organizer_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->first();
        if (! $org) {
            throw new ApiException(403, 'not_an_organizer', 'Your account is not part of an organizer team.');
        }

        $device = ScannerDevice::firstOrNew(['organizer_id' => $org->id, 'device_uid' => $v['device_uid']]);
        if ($device->exists && $device->revoked_at) {
            throw new ApiException(403, 'device_revoked', 'This device has been revoked. Ask the organizer to register a new one.');
        }
        $device->fill(['name' => $v['name'], 'registered_by' => $device->registered_by ?? $user->id])->save();

        return response()->json(['data' => ['id' => $device->id, 'organizer_id' => $org->id, 'name' => $device->name]], $device->wasRecentlyCreated ? 201 : 200);
    }

    public function stats(Request $request, int $event): JsonResponse
    {
        if (! in_array($event, $this->scannableEventIds($request->user()), true)) {
            throw new ApiException(403, 'forbidden', 'You are not assigned to this event.');
        }

        return response()->json(['data' => $this->ops->stats(Event::findOrFail($event))]);
    }

    /** @return list<int> */
    private function scannableEventIds(User $user): array
    {
        $managed = Organizer::whereHas('members', fn ($q) => $q->where('users.id', $user->id)
            ->whereIn('organizer_members.role', [Organizer::ROLE_OWNER, Organizer::ROLE_MANAGER]))->pluck('id');

        return Event::whereIn('organizer_id', $managed)->pluck('id')
            ->merge(EventStaff::where('user_id', $user->id)->pluck('event_id'))->unique()->values()->all();
    }
}
