<?php

namespace App\Modules\Currencies\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Currencies\Models\Currency;
use App\Modules\Currencies\Services\CurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrencyController extends Controller
{
    public function __construct(private readonly CurrencyService $currencies) {}

    /** Public: currencies customers can pay in, with the rate applied at checkout. */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Currency::where('is_active', true)->orderByDesc('is_base')->orderBy('code')->get()->map->toApi(),
            'meta' => ['base_currency' => $this->currencies->baseCode()],
        ]);
    }

    /** Admin: every currency, active or not. */
    public function adminIndex(): JsonResponse
    {
        return response()->json(['data' => Currency::orderByDesc('is_base')->orderBy('code')->get()->map->toApi()]);
    }

    public function upsert(Request $request, string $code): JsonResponse
    {
        $request->merge(['code' => strtoupper($code)]);
        $data = $request->validate([
            'code' => ['required', 'regex:/^[A-Z]{3}$/'],
            'name' => [Currency::where('code', strtoupper($code))->exists() ? 'sometimes' : 'required', 'string', 'max:60'],
            'symbol' => [Currency::where('code', strtoupper($code))->exists() ? 'sometimes' : 'required', 'string', 'max:8'],
            'rate_per_base' => ['sometimes', 'nullable', 'decimal:0,8', 'gt:0', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        unset($data['code']);
        if (isset($data['rate_per_base'])) {
            $data['rate_per_base'] = (string) $data['rate_per_base'];
        }

        return response()->json(['data' => $this->currencies->upsert($code, $data, $request->user())->toApi()]);
    }
}
