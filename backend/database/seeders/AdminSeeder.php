<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('bootstrap_admin.email');
        $password = config('bootstrap_admin.password');

        if (! $email || ! $password) {
            $this->command?->info('Admin bootstrap skipped. Register an account or configure ADMIN_EMAIL and ADMIN_PASSWORD.');

            return;
        }

        Validator::make([
            'email' => $email,
            'password' => $password,
        ], [
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:12'],
        ])->validate();

        // Never reset credentials or promote an existing public account on a seed rerun.
        if (User::where('email', $email)->exists()) {
            $this->command?->warn('Admin email already exists; no account changes were made.');

            return;
        }

        User::create([
            'name' => 'Administrator',
            'email' => $email,
            'password' => $password,
            'role' => User::ROLE_ADMIN,
        ]);
    }
}
