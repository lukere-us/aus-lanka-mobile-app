<?php

namespace App\Modules\Tickets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tickets\Services\CheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckInController extends Controller
{
    public function __invoke(Request $request, CheckInService $checkIns): JsonResponse
    {
        $v = $request->validate([
            'qr' => ['required', 'string', 'max:64'],
            'event_id' => ['required', 'integer'],
        ]);

        $ticket = $checkIns->checkIn($request->user(), $v['qr'], (int) $v['event_id'])->load(['ticketType', 'holder']);

        return response()->json(['data' => [
            'result' => 'admitted',
            'ticket_id' => $ticket->id,
            'ticket_type' => $ticket->ticketType->name,
            'holder_name' => $ticket->holder->name,
            'checked_in_at' => $ticket->checked_in_at->toIso8601ZuluString(),
        ]]);
    }
}
