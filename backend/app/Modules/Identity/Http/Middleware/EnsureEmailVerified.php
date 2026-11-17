<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Http\Responses\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Purchases need a verified email: receipts and tickets go there, and it limits throwaway-account fraud. */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->email_verified_at) {
            throw new ApiException(403, 'email_not_verified', 'Please verify your email address before buying.');
        }

        return $next($request);
    }
}
