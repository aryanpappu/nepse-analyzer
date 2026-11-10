<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Your email address is already verified.']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'A verification link has been sent to your email address.']);
    }

    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        abort_unless((string) $user->id === $id && hash_equals(sha1($user->email), $hash), 403);

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json(['message' => 'Your email address has been verified.']);
    }
}
