<?php

namespace App\Modules\Tickets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tickets\Http\Resources\TicketResource;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Customer ticket wallet (F07 / H07 API). */
class WalletController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return TicketResource::collection(Ticket::where('user_id', $request->user()->id)
            ->with(['event.venue', 'ticketType', 'order'])
            ->join('events', 'events.id', '=', 'tickets.event_id')
            ->orderBy('events.starts_at')->orderBy('tickets.id')
            ->select('tickets.*')
            ->paginate(50));
    }

    public function show(Request $request, int $ticket): TicketResource
    {
        return new TicketResource(Ticket::where('user_id', $request->user()->id)
            ->with(['event.venue', 'ticketType', 'order'])->findOrFail($ticket));
    }
}
