<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\FeatureTestCase;

class PrivateSiteTest extends FeatureTestCase
{
    public function test_market_data_is_public_by_default(): void
    {
        $this->getJson('/api/sectors')->assertOk();
    }

    public function test_private_site_requires_login_for_market_data(): void
    {
        config(['public_site.require_login' => true]);

        $this->getJson('/api/sectors')->assertUnauthorized();
        $this->getJson('/api/stocks')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/sectors')->assertOk();
    }

    public function test_private_site_keeps_login_page_dependencies_public(): void
    {
        config(['public_site.require_login' => true, 'public_site.registration_open' => false]);

        $this->getJson('/api/site-information')->assertOk()
            ->assertJsonPath('data.require_login', true)
            ->assertJsonPath('data.registration_open', false);
        $this->getJson('/api/health')->assertOk();
    }

    public function test_closed_registration_refuses_sign_up(): void
    {
        config(['public_site.registration_open' => false]);

        $this->postJson('/api/auth/register', [
            'name' => 'Someone',
            'email' => 'someone@example.com',
            'password' => 'a-secure-password',
            'password_confirmation' => 'a-secure-password',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'someone@example.com']);
    }

    public function test_admin_can_create_an_account_with_a_default_portfolio(): void
    {
        config(['public_site.registration_open' => false]);
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $this->postJson('/api/admin/users', [
            'name' => 'Invited Person',
            'email' => 'invited@example.com',
            'password' => 'a-long-initial-password',
        ])->assertCreated()->assertJsonPath('data.role', User::ROLE_USER);

        $user = User::where('email', 'invited@example.com')->firstOrFail();
        $this->assertSame(1, $user->portfolios()->where('is_default', true)->count());

        $this->getJson('/api/admin/users')->assertOk()->assertJsonFragment(['email' => 'invited@example.com']);
    }

    public function test_non_admin_cannot_manage_accounts(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_USER]));

        $this->getJson('/api/admin/users')->assertForbidden();
        $this->postJson('/api/admin/users', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'a-long-initial-password',
            'role' => User::ROLE_ADMIN,
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);
    }

    public function test_admin_created_password_must_be_strong(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $this->postJson('/api/admin/users', [
            'name' => 'Weak',
            'email' => 'weak@example.com',
            'password' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }
}
