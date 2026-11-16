<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation id (B06). A valid incoming X-Request-Id is kept so the
 * mobile app / portal can trace a call end to end; otherwise a UUID is generated.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get(self::HEADER, '');
        $id = preg_match('/^[A-Za-z0-9\-_.]{8,64}$/', $incoming) ? $incoming : (string) Str::uuid();

        $request->attributes->set('request_id', $id);
        Log::shareContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    public static function current(?Request $request = null): ?string
    {
        $request ??= request();

        return $request?->attributes->get('request_id');
    }
}
