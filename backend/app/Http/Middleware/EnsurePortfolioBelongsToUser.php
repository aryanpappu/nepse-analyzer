<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Portfolio;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorize portfolio ownership *before* validation runs.
 *
 * Every portfolio-scoped controller already calls
 * `AuthorizesPortfolioAccess::authorizeOwner()`, but a FormRequest validates
 * before the controller method is ever entered — so a request to another user's
 * portfolio carrying an invalid body answered 422 instead of 403. Nothing
 * leaked, but the 422 confirmed the portfolio id exists before authorization
 * had been evaluated, and an authorization boundary should not be observable
 * through validation.
 *
 * Applied to the route group, so it runs ahead of any FormRequest. The
 * in-controller checks stay as defence in depth: they cost one comparison and
 * they keep each action correct if it is ever reached by another route.
 */
class EnsurePortfolioBelongsToUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $portfolio = $request->route('portfolio');

        // Not every route in the group carries one, and a missing binding is
        // a 404 for the router to raise, not an authorization failure.
        if (! $portfolio instanceof Portfolio) {
            return $next($request);
        }

        $user = $request->user();

        abort_if($user === null, 401);

        if ($user->isAdmin() || $portfolio->user_id === $user->id) {
            return $next($request);
        }

        abort(403, 'This portfolio belongs to another user.');
    }
}
