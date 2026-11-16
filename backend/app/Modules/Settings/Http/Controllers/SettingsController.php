<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(private readonly PlatformSettings $settings) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->present($this->settings->all())]);
    }

    public function update(Request $request): JsonResponse
    {
        $rules = [];
        foreach (PlatformSettings::DEFINITIONS as $key => [, $r]) {
            $rules[$key] = array_merge(['sometimes'], $r);
        }
        $values = $request->validate($rules);

        return response()->json(['data' => $this->present($this->settings->update($values, $request->user()))]);
    }

    private function present(array $values): array
    {
        return collect(PlatformSettings::DEFINITIONS)->map(fn ($def, $key) => [
            'key' => $key, 'value' => $values[$key], 'description' => $def[2],
        ])->values()->all();
    }
}
