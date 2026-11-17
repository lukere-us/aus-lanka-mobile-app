<?php

namespace App\Modules\EventOps\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\EventOps\Models\EventGate;
use App\Modules\EventOps\Models\EventStaff;
use App\Modules\EventOps\Models\ScannerDevice;
use App\Modules\EventOps\Models\TicketScan;
use App\Modules\EventOps\Services\EventOpsService;
use App\Modules\Events\Models\Event;
use App\Modules\Organizers\Services\OrganizerContext;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Organizer portal: gates, staff, devices, attendees, sales, live stats, gate exceptions. Own events only. */
class OrganizerOpsController extends Controller
{
    public function __construct(private readonly EventOpsService $ops, private readonly OrganizerContext $context) {}

    public function gates(Request $request, int $event): JsonResponse
    {
        return response()->json(['data' => EventGate::where('event_id', $this->own($request, $event)->id)->orderBy('name')->get(['id', 'name'])]);
    }

    public function addGate(Request $request, int $event): JsonResponse
    {
        $e = $this->own($request, $event);
        $v = $request->validate(['name' => ['required', 'string', 'max:60']]);
        abort_if(EventGate::where('event_id', $e->id)->where('name', $v['name'])->exists(), 409, 'A gate with this name already exists.');

        return response()->json(['data' => EventGate::create(['event_id' => $e->id, 'name' => $v['name']])->only(['id', 'name'])], 201);
    }

    public function removeGate(Request $request, int $event, int $gate): Response
    {
        EventGate::where('event_id', $this->own($request, $event)->id)->whereKey($gate)->delete();

        return response()->noContent();
    }

    public function staff(Request $request, int $event): JsonResponse
    {
        $rows = EventStaff::where('event_id', $this->own($request, $event)->id)->with(['user:id,name,email', 'gate:id,name'])->get();

        return response()->json(['data' => $rows->map(fn ($r) => [
            'user_id' => $r->user_id, 'name' => $r->user->name, 'email' => $r->user->email, 'gate' => $r->gate?->only(['id', 'name']),
        ])]);
    }

    public function assignStaff(Request $request, int $event): JsonResponse
    {
        $e = $this->own($request, $event);
        $v = $request->validate(['user_id' => ['required', 'integer'], 'gate_id' => ['nullable', 'integer']]);
        $row = $this->ops->assignStaff($e, (int) $v['user_id'], $v['gate_id'] ?? null, $request->user());

        return response()->json(['data' => ['user_id' => $row->user_id, 'gate_id' => $row->gate_id]], 201);
    }

    public function unassignStaff(Request $request, int $event, int $user): Response
    {
        $this->ops->unassignStaff($this->own($request, $event), $user);

        return response()->noContent();
    }

    public function devices(Request $request): JsonResponse
    {
        $org = $this->context->for($request->user());

        return response()->json(['data' => ScannerDevice::where('organizer_id', $org->id)->latest('id')->get()
            ->map(fn ($d) => $d->only(['id', 'name', 'device_uid', 'last_used_at', 'revoked_at', 'created_at']))]);
    }

    public function revokeDevice(Request $request, int $device): JsonResponse
    {
        $d = ScannerDevice::where('organizer_id', $this->context->for($request->user())->id)->findOrFail($device);
        $d->update(['revoked_at' => now()]);

        return response()->json(['data' => $d->only(['id', 'name', 'revoked_at'])]);
    }

    public function attendees(Request $request, int $event): JsonResponse
    {
        $page = $this->attendeeQuery($request, $this->own($request, $event))->paginate(100);

        return response()->json(['data' => collect($page->items())->map(fn ($t) => $this->attendeeRow($t)), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage()]]);
    }

    public function attendeesCsv(Request $request, int $event): StreamedResponse
    {
        $e = $this->own($request, $event);
        $query = $this->attendeeQuery($request, $e);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Ticket ID', 'Ticket number', 'Name', 'Email', 'Ticket type', 'Status', 'Checked in at (UTC)', 'Order']);
            $query->chunk(500, function ($tickets) use ($out) {
                foreach ($tickets as $t) {
                    $r = $this->attendeeRow($t);
                    fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, // no spreadsheet formulas
                        [$r['ticket_id'], $r['number'], $r['name'], $r['email'], $r['ticket_type'], $r['status'], $r['checked_in_at'], $r['order']]));
                }
            });
            fclose($out);
        }, "attendees-{$e->slug}.csv", ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    public function sales(Request $request, int $event): JsonResponse
    {
        return response()->json(['data' => $this->ops->sales($this->own($request, $event))]);
    }

    public function stats(Request $request, int $event): JsonResponse
    {
        return response()->json(['data' => $this->ops->stats($this->own($request, $event))]);
    }

    public function scans(Request $request, int $event): JsonResponse
    {
        $e = $this->own($request, $event);
        $page = TicketScan::where('event_id', $e->id)->latest('id')->paginate(100);

        return response()->json(['data' => $page->items(), 'meta' => ['total' => $page->total()]]);
    }

    public function admit(Request $request, int $event, int $ticket): JsonResponse
    {
        $e = $this->own($request, $event);
        $v = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        return response()->json(['data' => $this->attendeeRow($this->ops->manualAdmit($e, Ticket::findOrFail($ticket), $v['reason'], $request->user())->load(['holder', 'ticketType', 'order']))]);
    }

    public function undo(Request $request, int $event, int $ticket): JsonResponse
    {
        $e = $this->own($request, $event);
        $v = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        return response()->json(['data' => $this->attendeeRow($this->ops->undoCheckIn($e, Ticket::findOrFail($ticket), $v['reason'], $request->user())->load(['holder', 'ticketType', 'order']))]);
    }

    private function attendeeQuery(Request $request, Event $event)
    {
        $v = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:valid,used,cancelled,refunded']]);

        return Ticket::where('event_id', $event->id)
            ->whereIn('status', $v['status'] ?? null ? [$v['status']] : [Ticket::VALID, Ticket::USED])
            ->when($v['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q
                ->whereHas('holder', fn ($u) => $u->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"))
                ->orWhereHas('order', fn ($o) => $o->where('reference', $s))))
            ->with(['holder', 'ticketType', 'order'])
            ->orderBy('id');
    }

    private function attendeeRow(Ticket $t): array
    {
        return [
            'ticket_id' => $t->id,
            'number' => $t->sequence,
            'name' => $t->holder?->name,
            'email' => EventOpsService::maskEmail($t->holder?->email), // organizers see a partial email only (privacy)
            'ticket_type' => $t->ticketType?->name,
            'status' => $t->status,
            'checked_in_at' => $t->checked_in_at?->toIso8601ZuluString(),
            'order' => $t->order?->reference,
        ];
    }

    private function own(Request $request, int $eventId): Event
    {
        return Event::where('organizer_id', $this->context->for($request->user())->id)->findOrFail($eventId);
    }
}
