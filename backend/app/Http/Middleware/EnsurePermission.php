<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard: `permission:users.view` or `permission:orders.view|reports.view` (any of).
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            throw new AuthenticationException;
        }

        foreach (explode('|', $permissions) as $permission) {
            if ($user->can($permission)) {
                return $next($request);
            }
        }

        throw new AuthorizationException('You do not have permission to perform this action.');
    }
}
