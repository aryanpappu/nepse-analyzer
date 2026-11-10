<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\CashTransaction;
use App\Models\Portfolio;
use App\Models\PortfolioHolding;
use App\Models\PortfolioImport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Tests\FeatureTestCase;

class AccountDataTest extends FeatureTestCase
{
    public function test_export_requires_authentication_and_current_password(): void
    {
        $this->postJson('/api/auth/export', ['password' => 'wrong'])->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/auth/export', ['password' => 'wrong'])->assertUnprocessable();
    }

    public function test_export_includes_only_owned_records_and_excludes_credentials(): void
    {
        $owner = User::factory()->create(['password' => 'current-password']);
        $other = User::factory()->create();
        $ownPortfolio = Portfolio::factory()->create(['user_id' => $owner->id]);
        $otherPortfolio = Portfolio::factory()->create(['user_id' => $other->id]);
        CashTransaction::factory()->create(['portfolio_id' => $ownPortfolio->id, 'reference' => 'OWN-RECORD']);
        CashTransaction::factory()->create(['portfolio_id' => $otherPortfolio->id, 'reference' => 'OTHER-PRIVATE-RECORD']);
        $owner->createToken('private-token');
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/auth/export', ['password' => 'current-password'])->assertOk();
        $response->assertJsonCount(1, 'portfolios')->assertJsonCount(1, 'records.cash_transactions');
        $response->assertJsonPath('account.email', $owner->email);
        $response->assertJsonPath('records.cash_transactions.0.reference', 'OWN-RECORD');
        $this->assertStringNotContainsString('OTHER-PRIVATE-RECORD', $response->getContent());
        $this->assertStringNotContainsString($other->email, $response->getContent());
        $this->assertArrayNotHasKey('password', $response->json('account'));
        $this->assertArrayNotHasKey('remember_token', $response->json('account'));
        $this->assertArrayNotHasKey('tokens', $response->json());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_wrong_password_or_missing_confirmation_never_deletes_account(): void
    {
        $owner = User::factory()->create(['password' => 'current-password']);
        Sanctum::actingAs($owner);
        $this->deleteJson('/api/auth/me', ['password' => 'wrong', 'confirmation' => 'DELETE'])->assertUnprocessable();
        $this->deleteJson('/api/auth/me', ['password' => 'current-password'])->assertUnprocessable();
        $this->assertModelExists($owner);
    }

    public function test_deletion_cascades_owned_records_and_leaves_other_accounts_untouched(): void
    {
        $owner = User::factory()->create(['password' => 'current-password']);
        $other = User::factory()->create();
        $ownPortfolio = Portfolio::factory()->create(['user_id' => $owner->id]);
        $otherPortfolio = Portfolio::factory()->create(['user_id' => $other->id]);
        $holding = PortfolioHolding::factory()->create(['portfolio_id' => $ownPortfolio->id]);
        $cash = CashTransaction::factory()->create(['portfolio_id' => $ownPortfolio->id]);
        $import = PortfolioImport::create(['portfolio_id' => $ownPortfolio->id, 'user_id' => $owner->id]);
        $otherCash = CashTransaction::factory()->create(['portfolio_id' => $otherPortfolio->id]);
        $owner->createToken('active');
        Password::createToken($owner);
        DB::table('sessions')->insert(['id' => 'owner-session', 'user_id' => $owner->id, 'payload' => '', 'last_activity' => time()]);
        Sanctum::actingAs($owner);

        $this->deleteJson('/api/auth/me', ['password' => 'current-password', 'confirmation' => 'DELETE'])->assertOk();

        $this->assertModelMissing($owner);
        $this->assertModelMissing($ownPortfolio);
        $this->assertModelMissing($holding);
        $this->assertModelMissing($cash);
        $this->assertModelMissing($import);
        $this->assertModelExists($other);
        $this->assertModelExists($otherPortfolio);
        $this->assertModelExists($otherCash);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $owner->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $owner->email]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $owner->id]);
        $this->assertModelExists($holding->stock);
    }
}
