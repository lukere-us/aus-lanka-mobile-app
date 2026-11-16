<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Operational health check (B03/B06): app up + database reachable + queue backlog. */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = ['database' => 'ok', 'queue' => 'ok'];
        $pending = null;

        try {
            DB::select('select 1');
        } catch (Throwable) {
            $checks['database'] = 'failing';
        }

        try {
            if (config('queue.default') === 'database') {
                $pending = DB::table('jobs')->count();
            }
        } catch (Throwable) {
            $checks['queue'] = 'failing';
        }

        $healthy = ! in_array('failing', $checks, true);

        return response()->json([
            'data' => [
                'status' => $healthy ? 'ok' : 'degraded',
                'checks' => $checks,
                'queue_pending_jobs' => $pending,
                'version' => 'v1',
                'time' => now()->toIso8601ZuluString(),
            ],
        ], $healthy ? 200 : 503);
    }
}
