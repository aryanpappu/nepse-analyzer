<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Stock;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\FeatureTestCase;

class PublicReleaseTest extends FeatureTestCase
{
    public function test_seeding_without_credentials_creates_no_default_account(): void
    {
        config(['bootstrap_admin.email' => null, 'bootstrap_admin.password' => null]);
        $count = User::count();
        $this->seed(AdminSeeder::class);
        $this->assertSame($count, User::count());
    }

    public function test_bootstrap_never_promotes_or_resets_existing_users(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $hash = $user->password;
        config(['bootstrap_admin.email' => $user->email, 'bootstrap_admin.password' => 'unique-bootstrap-password']);
        $this->seed(AdminSeeder::class);
        $this->assertSame(User::ROLE_USER, $user->fresh()->role);
        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_bootstrap_creates_an_admin_without_personal_holdings(): void
    {
        config(['bootstrap_admin.email' => 'admin@example.com', 'bootstrap_admin.password' => 'unique-bootstrap-password']);
        $this->seed(AdminSeeder::class);
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue($admin->isAdmin());
        $this->assertSame(0, $admin->portfolios()->count());
    }

    public function test_registration_cannot_grant_administrator_access(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Public User',
            'email' => 'member@example.com',
            'password' => 'a-unique-password',
            'password_confirmation' => 'a-unique-password',
            'role' => User::ROLE_ADMIN,
        ])->assertCreated()->assertJsonPath('data.user.is_admin', false);
    }

    public function test_regular_users_cannot_create_shared_stocks(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_USER]));
        $this->postJson('/api/stocks', [])->assertForbidden();
    }

    public function test_regular_users_cannot_change_delete_or_reanalyze_shared_stocks(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_USER]));
        $stock = Stock::factory()->create();
        $this->patchJson('/api/stocks/'.$stock->symbol, [])->assertForbidden();
        $this->deleteJson('/api/stocks/'.$stock->symbol)->assertForbidden();
        $this->postJson('/api/stocks/'.$stock->symbol.'/analyze', [])->assertForbidden();
        $this->assertModelExists($stock);
    }

    public function test_admins_can_reach_stock_validation(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $this->postJson('/api/stocks', [])->assertUnprocessable();
    }
}
