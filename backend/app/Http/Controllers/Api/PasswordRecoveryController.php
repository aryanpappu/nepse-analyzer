<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\PasswordResetLink;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class PasswordRecoveryController extends Controller
{
    public function requestLink(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email', 'max:255']]);

        // Return the same response for unknown, throttled, and known addresses.
        Password::sendResetLink($validated, function (User $user, string $token): void {
            $user->notify(new PasswordResetLink($token));
        });

        return response()->json(['message' => 'If an account exists for that email, a password reset link will be sent.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = DB::transaction(fn (): string => Password::reset($validated, function (User $user, string $password): void {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            event(new PasswordReset($user));
        }));

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'This reset link is invalid or has expired. Request a new link.']);
        }

        return response()->json(['message' => 'Your password has been reset. Sign in with your new password.']);
    }
}
