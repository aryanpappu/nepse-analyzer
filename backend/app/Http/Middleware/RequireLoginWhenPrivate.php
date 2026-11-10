<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the public market-data routes login-only when the operator runs a
 * private instance (`SITE_REQUIRE_LOGIN=true`).
 *
 * Read at request time rather than when routes are registered, so flipping the
 * setting needs only a config refresh — not a route-cache rebuild.
 */
class RequireLoginWhenPrivate
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('public_site.require_login')) {
            return $next($request);
        }

        $user = Auth::guard('sanctum')->user();

        if ($user === null) {
            return response()->json(['message' => 'Sign in to view market data.'], 401);
        }

        // Downstream controllers read $request->user() for per-user decoration.
        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }
}
