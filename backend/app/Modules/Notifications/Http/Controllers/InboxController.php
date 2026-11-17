<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** In-app notification inbox (H08 API). */
class InboxController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = $request->user()->notifications()->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn ($n) => [
                'id' => $n->id, 'type' => $n->data['type'] ?? null, 'title' => $n->data['title'] ?? null, 'body' => $n->data['body'] ?? null,
                'data' => $n->data['data'] ?? [], 'read' => $n->read_at !== null, 'created_at' => $n->created_at?->toIso8601ZuluString(),
            ]),
            'meta' => ['unread' => $request->user()->unreadNotifications()->count(), 'total' => $page->total(), 'current_page' => $page->currentPage()],
        ]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $v = $request->validate(['ids' => ['sometimes', 'array', 'max:100'], 'ids.*' => ['string', 'uuid']]);
        $request->user()->unreadNotifications()
            ->when($v['ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->update(['read_at' => now()]);

        return response()->json(['data' => ['unread' => $request->user()->unreadNotifications()->count()]]);
    }
}
