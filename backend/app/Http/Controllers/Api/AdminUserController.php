<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\UserAccountCreator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Administrator-managed accounts. With registration closed this is the only way
 * a new person gets in. The admin sets an initial password and shares it with
 * the user, who should change it in Settings.
 */
class AdminUserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'role', 'email_verified_at', 'created_at']);

        return response()->json(['data' => $users->map(fn (User $user): array => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'email_verified_at' => $user->email_verified_at,
            'created_at' => $user->created_at,
        ])]);
    }

    public function store(Request $request, UserAccountCreator $accounts): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(12)],
            'role' => ['sometimes', 'string', Rule::in([User::ROLE_USER, User::ROLE_ADMIN])],
        ]);

        $user = $accounts->create(
            $validated['name'],
            $validated['email'],
            $validated['password'],
            $validated['role'] ?? User::ROLE_USER,
        );

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'email_verified_at' => null,
            'created_at' => $user->created_at,
        ]], 201);
    }
}
