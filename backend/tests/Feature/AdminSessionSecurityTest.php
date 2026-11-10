<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\FeatureTestCase;

class AdminSessionSecurityTest extends FeatureTestCase
{
    public function test_password_change_invalidates_existing_operator_browser_session(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'password' => 'original-password']);
        $originalHash = $admin->password;
        $admin->forceFill(['password' => 'changed-password'])->save();

        $this->actingAs($admin, 'web')
            ->withSession(['password_hash_web' => $originalHash])
            ->get('/admin/login')
            ->assertRedirect('/admin/login');

        $this->assertGuest('web');
    }

    public function test_matching_password_hash_preserves_operator_session(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin, 'web')
            ->withSession(['password_hash_web' => $admin->password])
            ->get('/admin/login')
            ->assertRedirect('/'.ltrim((string) config('horizon.path', 'horizon'), '/'));

        $this->assertAuthenticatedAs($admin, 'web');
    }
}
