<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a user together with the default portfolio every account needs.
 *
 * Shared by self-service registration and admin-created accounts so the two
 * paths cannot drift apart. One transaction: a failed portfolio insert must not
 * leave a half-created account behind.
 */
final class UserAccountCreator
{
    public function create(string $name, string $email, string $password, string $role): User
    {
        return DB::transaction(function () use ($name, $email, $password, $role): User {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role' => $role,
            ]);

            // Every account gets a portfolio so the UI has somewhere to write to.
            $user->portfolios()->create([
                'name' => 'My Portfolio',
                'currency' => 'NPR',
                'is_default' => true,
            ]);

            return $user;
        });
    }
}
