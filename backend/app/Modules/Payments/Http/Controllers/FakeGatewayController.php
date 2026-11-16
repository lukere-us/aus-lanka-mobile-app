<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentGatewayManager;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Local-only stand-in for the provider's hosted checkout page. Opening the checkout_url with
 * ?outcome=succeeded|failed|cancelled "pays" and fires the signed webhook, like a real provider would.
 * Routes are registered only outside production and only while the fake gateway is configured.
 */
class FakeGatewayController extends Controller
{
    public function __invoke(Request $request, string $reference, PaymentGatewayManager $gateways, PaymentService $payments): JsonResponse
    {
        $data = $request->validate(['outcome' => ['nullable', 'in:succeeded,failed,cancelled']]);
        $payment = Payment::where('provider', 'fake')->where('provider_reference', $reference)->firstOrFail();

        $outcome = $data['outcome'] ?? null;
        if ($outcome === null) {
            return response()->json(['data' => [
                'message' => 'Fake checkout. Add ?outcome=succeeded, failed or cancelled to finish it.',
                'amount' => $payment->amountMoney()->toArray(),
            ]]);
        }

        /** @var FakeGateway $gateway */
        $gateway = $gateways->driver('fake');
        $webhook = $gateway->simulate($reference, $outcome);
        $payments->receiveWebhook('fake', $webhook['body'], $webhook['headers']);

        return response()->json(['data' => [
            'order_reference' => $payment->order->reference,
            'payment_status' => $payment->fresh()->status,
        ]]);
    }
}
