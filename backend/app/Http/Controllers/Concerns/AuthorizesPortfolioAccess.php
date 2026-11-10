<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Portfolio;
use Illuminate\Http\Request;

/**
 * Route model binding resolves a portfolio by id alone, so every endpoint that
 * accepts one must confirm the caller owns it. Centralised here so the check is
 * one call and impossible to write subtly differently in each controller.
 */
trait AuthorizesPortfolioAccess
{
    protected function authorizeOwner(Request $request, Portfolio $portfolio): void
    {
        $user = $request->user();

        abort_if($user === null, 401);

        // Admins can inspect any portfolio; everyone else only their own.
        if ($user->isAdmin() || $portfolio->user_id === $user->id) {
            return;
        }

        abort(403, 'This portfolio belongs to another user.');
    }
}
