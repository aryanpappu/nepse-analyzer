<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Operator login (web guard)
|--------------------------------------------------------------------------
|
| Why this exists at all: /horizon runs behind `config('horizon.middleware')`,
| which is `['web']`, and its gate reads `$request->user()` — a *session* user.
| Everything else in this application authenticates with Sanctum bearer tokens
| and the React client never establishes a session, so outside `local` the
| Horizon gate could never see a user and the dashboard answered 403 to
| everyone. These three routes are the missing session login, and nothing more.
|
| This is deliberately not "authentication for the app". There is no
| registration, no password reset, no remembered login and no session-based
| access to any /api route. It exists so that an operator who is already an
| admin in `users.role` can prove it to the web guard, and it grants exactly
| what the Horizon gate then decides to grant — the gate itself is unchanged
| and still requires `isAdmin()`.
|
| Why not reverse-proxy auth instead: an htpasswd (or an oauth2-proxy) in front
| of /horizon means a second credential store that has to be kept in step with
| `users.role`, and it only works where that proxy is deployed — not on a bare
| `php artisan serve` box, not in staging, not for a contractor running the
| stack locally in a non-local APP_ENV. Keeping the decision in the application
| keeps one source of truth for who an operator is. Proxy auth is still worth
| adding *in front of* this as defence in depth; it is not a substitute for it.
|
| Note for deployment: these are closure routes, so `php artisan route:cache`
| will refuse to serialize them. That is already true of the `/` route above.
| If route caching is wanted later, move these three bodies into a controller.
|
*/

/**
 * Absolute URL of the Horizon dashboard.
 *
 * Read from `config('horizon.path')` (env `HORIZON_PATH`) rather than
 * hardcoded, because that path is intended to be changed — an unguessable path
 * was the only protection /horizon had before this login existed, and keeping
 * it unguessable is still worthwhile.
 */
$horizonUrl = static fn (): string => url('/'.ltrim((string) config('horizon.path', 'horizon'), '/'));

/**
 * The session guard.
 *
 * `Auth::guard()` is documented as returning `Guard|StatefulGuard`, and only
 * the stateful half has `attempt()`/`logout()`. `config/auth.php` defines `web`
 * with the `session` driver, so the guard really is a `SessionGuard`; narrowing
 * it here states that once instead of leaving every call site unprovable. It is
 * resolved per request, not at route-registration time.
 */
$webGuard = static function (): StatefulGuard {
    /** @var StatefulGuard $guard */
    $guard = Auth::guard('web');

    return $guard;
};

Route::prefix('admin')->group(function () use ($horizonUrl, $webGuard): void {
    Route::get('login', function () use ($horizonUrl, $webGuard) {
        // Already an operator? Do not show a login form; go where they meant.
        $user = $webGuard()->user();

        if ($user instanceof User && $user->isAdmin()) {
            return redirect()->intended($horizonUrl());
        }

        return view('admin.login');
    })->name('admin.login');

    Route::post('login', function (Request $request) use ($horizonUrl, $webGuard) {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        if (! $webGuard()->attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        // Session fixation: the identifier the visitor arrived with must not
        // become the identifier of the authenticated session.
        $request->session()->regenerate();

        $user = $webGuard()->user();

        if (! $user instanceof User || ! $user->isAdmin()) {
            // Correct password, wrong role. Do not leave a usable session
            // behind — this login has no purpose other than Horizon, so a
            // non-admin gets nothing from it.
            $webGuard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'This account is not authorised to administer the queue.',
            ]);
        }

        return redirect()->intended($horizonUrl());
    })
        // Five attempts per minute per IP. Enough for a human with a password
        // manager, useless for guessing.
        ->middleware('throttle:5,1')
        ->name('admin.login.attempt');

    Route::post('logout', function (Request $request) use ($webGuard) {
        $webGuard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    })->name('admin.logout');
});
