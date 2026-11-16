<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Finance/admin order lookup (feeds I06 finance screens and J04 support lookup). */
class AdminOrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $v = $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'reference' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = Order::query()
            ->with(['items', 'latestPayment', 'user'])
            ->when($v['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($v['reference'] ?? null, fn ($q, $r) => $q->where('reference', $r))
            ->when($v['email'] ?? null, fn ($q, $e) => $q->whereHas('user', fn ($u) => $u->where('email', $e)))
            ->when($v['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($v['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d))
            ->latest('id')
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        return OrderResource::collection($orders);
    }

    public function show(string $reference): OrderResource
    {
        return new OrderResource(Order::where('reference', $reference)->with(['items', 'latestPayment', 'user'])->firstOrFail());
    }
}
