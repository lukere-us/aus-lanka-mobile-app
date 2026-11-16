<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\Requests\CheckoutRequest;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\CheckoutService;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Customer purchase APIs (D05). Customers only ever see their own orders. */
class OrderController extends Controller
{
    public function __construct(private readonly CheckoutService $checkout, private readonly PaymentService $payments) {}

    public function store(CheckoutRequest $request): JsonResponse
    {
        [$order, $created] = $this->checkout->checkout(
            $request->user(),
            array_values($request->validated('items')),
            $request->validated('idempotency_key'),
        );

        return (new OrderResource($order))->response()->setStatusCode($created ? 201 : 200);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);

        return OrderResource::collection(
            Order::where('user_id', $request->user()->id)
                ->with(['items', 'latestPayment'])
                ->latest('id')
                ->paginate($request->integer('per_page', 20)),
        );
    }

    public function show(Request $request, string $reference): OrderResource
    {
        return new OrderResource($this->ownOrder($request, $reference));
    }

    /**
     * Called by the app when the customer returns from the provider. The server asks the provider
     * itself; the app's word is never taken (plan 2.1).
     */
    public function verifyPayment(Request $request, string $reference): OrderResource
    {
        $order = $this->ownOrder($request, $reference);
        if ($order->latestPayment) {
            $this->payments->verify($order->latestPayment, 'customer_return');
        }

        return new OrderResource($order->fresh(['items', 'latestPayment']));
    }

    public function retryPayment(Request $request, string $reference): OrderResource
    {
        return new OrderResource($this->checkout->retryPayment($this->ownOrder($request, $reference)));
    }

    private function ownOrder(Request $request, string $reference): Order
    {
        return Order::where('reference', $reference)
            ->where('user_id', $request->user()->id)
            ->with(['items', 'latestPayment'])
            ->firstOrFail();
    }
}
