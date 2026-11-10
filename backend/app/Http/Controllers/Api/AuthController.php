<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\UserAccountCreator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(Request $request, UserAccountCreator $accounts): JsonResponse
    {
        if (! config('public_site.registration_open')) {
            return response()->json([
                'message' => 'Registration is closed. Ask an administrator to create an account for you.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = $accounts->create($validated['name'], $validated['email'], $validated['password'], User::ROLE_USER);

        return response()->json([
            'data' => [
                'token' => $user->createToken('api')->plainTextToken,
                'user' => $this->userPayload($user),
            ],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Throttle per email+IP so an attacker cannot spray one account.
        $throttleKey = strtolower($validated['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Try again in '
                    .RateLimiter::availableIn($throttleKey).' seconds.',
            ])->status(429);
        }

        $user = User::where('email', $validated['email'])->first();

        if ($user === null || ! Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 300);

            // One message for both cases, so the response cannot be used to
            // enumerate which emails are registered.
            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        return response()->json([
            'data' => [
                'token' => $user->createToken('api')->plainTextToken,
                'user' => $this->userPayload($user),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userPayload($request->user())]);
    }

    /**
     * Update the signed-in user's own profile and comparison settings.
     *
     * The deposit rate lived only in config, so every user was measured
     * against the same number. Rates differ by bank and by negotiation, and
     * the question worth answering is "did this beat leaving it in *my* bank".
     *
     * Null clears a preference back to the configured default, which is not
     * the same as choosing 0% — so the field is nullable rather than defaulted.
     * `role` is deliberately not accepted here: a user must not be able to
     * make themselves an admin.
     */
    public function updateMe(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'email' => [
                'sometimes', 'string', 'email', 'max:190',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            // Require password confirmation before changing sign-in credentials.
            'current_password' => ['required_with:password', 'string'],
            'password' => ['sometimes', 'string', Password::min(8), 'confirmed'],

            'benchmark_fd_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:30'],
            'benchmark_index_slug' => ['sometimes', 'nullable', 'string', 'max:80'],
            'investor_class' => ['sometimes', 'nullable', 'string', 'in:individual,institution'],
        ]);

        $previousEmail = $user->email;
        $changingEmail = isset($validated['email']) && $validated['email'] !== $user->email;
        $changingPassword = isset($validated['password']);

        if ($changingPassword || $changingEmail) {
            if (! Hash::check($validated['current_password'] ?? '', $user->password)) {
                return response()->json([
                    'message' => 'That current password is not correct.',
                    'errors' => ['current_password' => ['That current password is not correct.']],
                ], 422);
            }

        }

        if ($changingPassword) {
            $user->password = Hash::make($validated['password']);
            $user->remember_token = Str::random(60);
        }

        if ($changingEmail) {
            $user->email_verified_at = null;
        }

        foreach (['name', 'email', 'benchmark_fd_rate', 'benchmark_index_slug', 'investor_class'] as $field) {
            if (array_key_exists($field, $validated)) {
                $user->{$field} = $validated[$field];
            }
        }

        DB::transaction(function () use ($user, $changingPassword, $changingEmail, $previousEmail): void {
            $user->save();

            if ($changingPassword || $changingEmail) {
                DB::table(config('auth.passwords.users.table'))->whereIn('email', [$previousEmail, $user->email])->delete();
            }

            if ($changingPassword) {
                $tokens = $user->tokens();
                $currentToken = $user->currentAccessToken();
                if ($currentToken instanceof PersonalAccessToken) {
                    $tokens->where('id', '<>', $currentToken->getKey());
                }
                $tokens->delete();
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        });

        /*
         * The deposit rate feeds the performance report and both benchmarks,
         * all of which are cached per portfolio — without this a saved rate
         * would appear to do nothing until the TTL expired.
         */
        foreach ($user->portfolios as $portfolio) {
            Cache::forget("portfolio:summary:{$portfolio->id}");
            PerformanceController::invalidateBenchmark($portfolio);
        }

        return response()->json([
            'message' => 'Profile updated.',
            'data' => $this->userPayload($user->fresh()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        $user->loadMissing('defaultPortfolio');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at,
            'role' => $user->role,
            'is_admin' => $user->isAdmin(),
            'default_portfolio_id' => $user->defaultPortfolio?->id,

            /*
             * Comparison settings. `benchmark_fd_rate` is null when the user
             * has not set one — reported alongside the config default so the
             * UI can show what is actually in force without pretending an
             * unset preference is a chosen 0%.
             */
            'benchmark_fd_rate' => $user->benchmark_fd_rate,
            'benchmark_fd_rate_effective' => $user->benchmark_fd_rate
                ?? (float) config('nepse.benchmark.fixed_deposit_rate', 8.0),
            'benchmark_fd_rate_default' => (float) config('nepse.benchmark.fixed_deposit_rate', 8.0),
            'benchmark_index_slug' => $user->benchmark_index_slug,
            'benchmark_index_slug_effective' => $user->benchmark_index_slug
                ?? (string) config('nepse.benchmark.market_index_slug', 'nepse-index'),
            'investor_class' => $user->investor_class,
            'investor_class_effective' => $user->investor_class
                ?? (string) config('nepse.capital_gains_tax.default_investor_class', 'individual'),
        ];
    }
}
