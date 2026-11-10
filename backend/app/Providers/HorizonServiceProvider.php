<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Who may open /horizon outside local.
     *
     * The dashboard can retry and delete jobs, so it is admin-only rather than
     * merely authenticated. The default stub matched on a hardcoded email list;
     * this app already carries a role column, so it is used instead — adding an
     * operator is a database change, not a deploy.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user = null): bool {
            return $user?->isAdmin() === true;
        });
    }

    /**
     * Wire the gate into Horizon, and give a guest somewhere to go.
     *
     * The gate above is only half of an access story. Horizon's own middleware
     * calls this check and throws a bare 403 when it fails, and the gate can
     * only ever see a user that the *session* guard resolved. This application's
     * API is stateless Sanctum bearer tokens and the React client never creates
     * a web session, so before this change there was no sequence of requests
     * that could put an authenticated user in front of the gate outside local:
     * /horizon answered 403 to everyone, admins included.
     *
     * The fix is a session login for the `web` guard (see routes/web.php), plus
     * the redirect below so that arriving at /horizon actually leads somewhere.
     * Note what is *not* changed: the gate still demands `isAdmin()`, and
     * Horizon's `config('horizon.middleware')` still holds just `['web']`.
     * Logging in only produces a session; authorization is still decided here.
     *
     * Three outcomes, deliberately distinct:
     *
     *  - local: open, matching the framework default this class inherits.
     *  - guest on a page request: redirected to the login form, with /horizon
     *    remembered as the intended destination.
     *  - authenticated non-admin, or any JSON/XHR request: plain 403. A signed-in
     *    non-admin is not bounced to a login page they have already been
     *    through, and the dashboard's own polling requests get a status code
     *    rather than a redirect into HTML.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(function (Request $request): bool {
            if ($this->app->environment('local')) {
                return true;
            }

            if (Gate::check('viewHorizon', [$request->user()])) {
                return true;
            }

            if ($request->user() === null && ! $request->expectsJson()) {
                throw new HttpResponseException(
                    redirect()->guest(route('admin.login'))
                );
            }

            return false;
        });
    }
}
