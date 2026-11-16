<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiException;
use App\Modules\Payments\Exceptions\InvalidWebhookSignature;
use App\Modules\Payments\Services\PaymentGatewayManager;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, PaymentService $payments, PaymentGatewayManager $gateways): JsonResponse
    {
        if (! $gateways->exists($provider)) {
            throw new ApiException(404, 'not_found', 'The requested resource was not found.');
        }

        $headers = array_map(fn ($values) => (string) ($values[0] ?? ''), array_change_key_case($request->headers->all()));

        try {
            $result = $payments->receiveWebhook($provider, $request->getContent(), $headers);
        } catch (InvalidWebhookSignature $e) {
            Log::warning('Rejected payment webhook', ['provider' => $provider, 'reason' => $e->getMessage(), 'ip' => $request->ip()]);

            throw new ApiException(400, 'invalid_signature', 'Webhook signature verification failed.');
        }

        return response()->json(['data' => ['status' => $result]]);
    }
}
