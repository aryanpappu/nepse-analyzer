<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\CashTransaction;
use App\Models\Portfolio;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * HTTP coverage for `/api/portfolios/{portfolio}/cash`.
 *
 * The point of these tests is the replay rule: amending or voiding a row must
 * rewrite every later `balance_after` from the surviving ledger rather than
 * patching the balance columns, and must refuse a correction that would leave
 * an account overdrawn — because `record()` would have refused the same
 * movement had it been entered that way in the first place.
 */
final class CashLedgerTest extends FeatureTestCase
{
    private User $user;

    private Portfolio $portfolio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->portfolio = Portfolio::factory()->forUser($this->user)->default()->create();

        Sanctum::actingAs($this->user);
    }

    // ------------------------------------------------------------------
    // Reading and recording
    // ------------------------------------------------------------------

    #[Test]
    public function it_returns_an_empty_ledger_with_zeroed_balances(): void
    {
        $this->getJson("/api/portfolios/{$this->portfolio->id}/cash")
            ->assertOk()
            ->assertJsonPath('data.cash_in_hand', 0)
            ->assertJsonPath('data.bank_balance', 0)
            ->assertJsonPath('data.investable_cash', 0)
            ->assertJsonCount(0, 'data.transactions');
    }

    #[Test]
    public function it_records_a_deposit_and_reports_the_new_balance(): void
    {
        $response = $this->postJson("/api/portfolios/{$this->portfolio->id}/cash", [
            'action' => 'deposit',
            'account' => 'bank',
            'amount' => 50_000,
            'reference' => 'VCH-1',
            'transacted_on' => Carbon::today()->subDays(3)->toDateString(),
        ])->assertCreated();

        $this->assertJsonNumber($response, 'data.bank_balance', 50_000);
        $this->assertJsonNumber($response, 'data.investable_cash', 50_000);

        $this->assertDatabaseHas('cash_transactions', [
            'portfolio_id' => $this->portfolio->id,
            'account' => 'bank',
            'type' => 'deposit',
            'amount' => 50000.00,
            'balance_after' => 50000.00,
        ]);
    }

    #[Test]
    public function it_refuses_a_withdrawal_that_would_overdraw_the_account(): void
    {
        $this->deposit(1_000, 'bank');

        $this->postJson("/api/portfolios/{$this->portfolio->id}/cash", [
            'action' => 'withdraw',
            'account' => 'bank',
            'amount' => 5_000,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (?string $message): bool => is_string($message)
                && str_contains($message, 'Insufficient bank balance'));

        $this->assertSame(1000.0, (float) $this->portfolio->fresh()->bank_balance);
    }

    #[Test]
    public function it_moves_money_between_accounts_as_a_pair(): void
    {
        $this->deposit(20_000, 'bank');

        $this->postJson("/api/portfolios/{$this->portfolio->id}/cash", [
            'action' => 'transfer',
            'from_account' => 'bank',
            'to_account' => 'cash',
            'amount' => 8_000,
        ])->assertCreated();

        $portfolio = $this->portfolio->fresh();

        $this->assertSame(12000.0, (float) $portfolio->bank_balance);
        $this->assertSame(8000.0, (float) $portfolio->cash_in_hand);

        // Both legs share one transfer group, so a later amend/void can apply
        // to both instead of unbalancing the transfer.
        $groups = CashTransaction::where('portfolio_id', $portfolio->id)
            ->where('type', 'transfer')
            ->pluck('transfer_group_id')
            ->unique();

        $this->assertCount(1, $groups);
        $this->assertNotNull($groups->first());
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    #[Test]
    public function it_rejects_a_cash_movement_with_no_action_or_amount(): void
    {
        $this->postJson("/api/portfolios/{$this->portfolio->id}/cash", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['action', 'amount']);
    }

    #[Test]
    public function it_rejects_a_zero_or_negative_amount(): void
    {
        $this->postJson("/api/portfolios/{$this->portfolio->id}/cash", [
            'action' => 'deposit',
            'account' => 'bank',
            'amount' => 0,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    #[Test]
    public function it_rejects_an_unknown_account_and_a_future_date(): void
    {
        $this->postJson("/api/portfolios/{$this->portfolio->id}/cash", [
            'action' => 'deposit',
            'account' => 'crypto',
            'amount' => 10,
            'transacted_on' => Carbon::tomorrow()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['account', 'transacted_on']);
    }

    #[Test]
    public function it_rejects_a_transfer_to_the_same_account(): void
    {
        $this->postJson("/api/portfolios/{$this->portfolio->id}/cash", [
            'action' => 'transfer',
            'from_account' => 'bank',
            'to_account' => 'bank',
            'amount' => 100,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to_account']);
    }

    #[Test]
    public function it_rejects_an_amend_with_no_fields(): void
    {
        $deposit = $this->deposit(10_000, 'bank');

        $this->patchJson("/api/portfolios/{$this->portfolio->id}/cash/{$deposit->id}", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    #[Test]
    public function it_rejects_an_amend_to_an_unknown_account(): void
    {
        $deposit = $this->deposit(10_000, 'bank');

        $this->patchJson("/api/portfolios/{$this->portfolio->id}/cash/{$deposit->id}", [
            'account' => 'wallet',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['account']);
    }

    // ------------------------------------------------------------------
    // Amend — the replay rule
    // ------------------------------------------------------------------

    #[Test]
    public function amending_an_amount_rewrites_every_later_balance_after(): void
    {
        [$first, $second, $third] = $this->threeRowLedger();

        $response = $this->patchJson("/api/portfolios/{$this->portfolio->id}/cash/{$first->id}", [
            'amount' => 80_000,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Cash transaction amended.');

        $this->assertJsonNumber($response, 'data.bank_balance', 45_000);

        // 80,000 in − 40,000 out + 5,000 in
        $this->assertSame(80000.0, (float) $first->fresh()->balance_after);
        $this->assertSame(40000.0, (float) $second->fresh()->balance_after);
        $this->assertSame(45000.0, (float) $third->fresh()->balance_after);

        $this->assertSame(45000.0, (float) $this->portfolio->fresh()->bank_balance);
    }

    #[Test]
    public function amending_preserves_the_pre_edit_values_once(): void
    {
        $deposit = $this->deposit(10_000, 'bank', Carbon::today()->subDays(5));

        $this->patchJson("/api/portfolios/{$this->portfolio->id}/cash/{$deposit->id}", [
            'amount' => 12_000,
            'reference' => 'VCH-CORRECTED',
        ])->assertOk();

        $this->patchJson("/api/portfolios/{$this->portfolio->id}/cash/{$deposit->id}", [
            'amount' => 13_000,
        ])->assertOk();

        $fresh = $deposit->fresh();

        $this->assertNotNull($fresh->corrected_at);
        // Still the *original* 10,000, not the 12,000 of the first correction.
        $this->assertSame(10000.0, (float) $fresh->original_values['amount']);
        $this->assertSame(13000.0, (float) $fresh->amount);
    }

    #[Test]
    public function amending_a_deposit_below_what_was_already_spent_is_refused(): void
    {
        [$first, $second, $third] = $this->threeRowLedger();

        $this->patchJson("/api/portfolios/{$this->portfolio->id}/cash/{$first->id}", [
            'amount' => 30_000,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (?string $message): bool => is_string($message)
                && str_contains($message, 'would overdraw the bank account'));

        // The refusal rolls the whole correction back — nothing moved.
        $this->assertSame(100000.0, (float) $first->fresh()->amount);
        $this->assertSame(100000.0, (float) $first->fresh()->balance_after);
        $this->assertSame(60000.0, (float) $second->fresh()->balance_after);
        $this->assertSame(65000.0, (float) $third->fresh()->balance_after);
        $this->assertSame(65000.0, (float) $this->portfolio->fresh()->bank_balance);
    }

    #[Test]
    public function amending_the_date_reorders_the_replay(): void
    {
        $old = $this->deposit(10_000, 'bank', Carbon::today()->subDays(30));
        $recent = $this->deposit(5_000, 'bank', Carbon::today()->subDays(10));

        // Move the older deposit after the newer one; the running balances
        // must be rebuilt in the new order.
        $this->patchJson("/api/portfolios/{$this->portfolio->id}/cash/{$old->id}", [
            'transacted_on' => Carbon::today()->subDays(5)->toDateString(),
        ])->assertOk();

        $this->assertSame(5000.0, (float) $recent->fresh()->balance_after);
        $this->assertSame(15000.0, (float) $old->fresh()->balance_after);
        $this->assertSame(15000.0, (float) $this->portfolio->fresh()->bank_balance);
    }

    #[Test]
    public function amending_the_account_moves_the_money_and_replays_both_accounts(): void
    {
        $deposit = $this->deposit(25_000, 'bank', Carbon::today()->subDays(4));

        $response = $this->patchJson("/api/portfolios/{$this->portfolio->id}/cash/{$deposit->id}", [
            'account' => 'cash',
        ])->assertOk();

        $this->assertJsonNumber($response, 'data.bank_balance', 0);
        $this->assertJsonNumber($response, 'data.cash_in_hand', 25_000);

        $this->assertSame('cash', $deposit->fresh()->account);
    }

    #[Test]
    public function a_voided_row_cannot_be_amended(): void
    {
        $deposit = $this->deposit(10_000, 'bank');

        $this->deleteJson("/api/portfolios/{$this->portfolio->id}/cash/{$deposit->id}")
            ->assertOk();

        $this->patchJson("/api/portfolios/{$this->portfolio->id}/cash/{$deposit->id}", [
            'amount' => 12_000,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (?string $message): bool => is_string($message)
                && str_contains($message, 'Cannot amend a voided transaction'));
    }

    // ------------------------------------------------------------------
    // Void
    // ------------------------------------------------------------------

    #[Test]
    public function voiding_a_row_removes_it_from_the_balance_and_replays_the_rest(): void
    {
        [$first, $second, $third] = $this->threeRowLedger();

        $response = $this->deleteJson("/api/portfolios/{$this->portfolio->id}/cash/{$third->id}", [
            'reason' => 'Duplicate entry.',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Cash transaction voided.');

        $this->assertJsonNumber($response, 'data.bank_balance', 60_000);

        $voided = $third->fresh();

        $this->assertNotNull($voided->voided_at);
        $this->assertSame('Duplicate entry.', $voided->void_reason);

        $this->assertSame(100000.0, (float) $first->fresh()->balance_after);
        $this->assertSame(60000.0, (float) $second->fresh()->balance_after);
        $this->assertSame(60000.0, (float) $this->portfolio->fresh()->bank_balance);
    }

    #[Test]
    public function voiding_a_deposit_that_later_withdrawals_relied_on_is_refused(): void
    {
        [$first, , $third] = $this->threeRowLedger();

        $this->deleteJson("/api/portfolios/{$this->portfolio->id}/cash/{$first->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', fn (?string $message): bool => is_string($message)
                && str_contains($message, 'would overdraw the bank account'));

        $this->assertNull($first->fresh()->voided_at);
        $this->assertSame(65000.0, (float) $third->fresh()->balance_after);
        $this->assertSame(65000.0, (float) $this->portfolio->fresh()->bank_balance);
    }

    #[Test]
    public function voided_rows_drop_out_of_the_listing_unless_asked_for(): void
    {
        $deposit = $this->deposit(10_000, 'bank');

        $this->deleteJson("/api/portfolios/{$this->portfolio->id}/cash/{$deposit->id}")->assertOk();

        $this->getJson("/api/portfolios/{$this->portfolio->id}/cash")
            ->assertOk()
            ->assertJsonCount(0, 'data.transactions');

        $this->getJson("/api/portfolios/{$this->portfolio->id}/cash?include_voided=1")
            ->assertOk()
            ->assertJsonCount(1, 'data.transactions')
            ->assertJsonPath('data.transactions.0.voided', true)
            ->assertJsonPath('data.transactions.0.void_reason', 'Entered in error.');
    }

    #[Test]
    public function voiding_an_already_voided_row_is_idempotent(): void
    {
        $deposit = $this->deposit(10_000, 'bank');

        $this->deleteJson("/api/portfolios/{$this->portfolio->id}/cash/{$deposit->id}")->assertOk();
        $voidedAt = $deposit->fresh()->voided_at;

        $response = $this->deleteJson("/api/portfolios/{$this->portfolio->id}/cash/{$deposit->id}")
            ->assertOk();

        $this->assertJsonNumber($response, 'data.bank_balance', 0);
        $this->assertEquals($voidedAt, $deposit->fresh()->voided_at);
    }

    #[Test]
    public function voiding_one_transfer_leg_voids_both(): void
    {
        $this->deposit(20_000, 'bank');

        $this->postJson("/api/portfolios/{$this->portfolio->id}/cash", [
            'action' => 'transfer',
            'from_account' => 'bank',
            'to_account' => 'cash',
            'amount' => 8_000,
        ])->assertCreated();

        $leg = CashTransaction::where('portfolio_id', $this->portfolio->id)
            ->where('type', 'transfer')
            ->orderBy('id')
            ->firstOrFail();

        $response = $this->deleteJson("/api/portfolios/{$this->portfolio->id}/cash/{$leg->id}")
            ->assertOk();

        $this->assertJsonNumber($response, 'data.bank_balance', 20_000);
        $this->assertJsonNumber($response, 'data.cash_in_hand', 0);

        $this->assertSame(
            0,
            CashTransaction::where('portfolio_id', $this->portfolio->id)
                ->where('type', 'transfer')
                ->whereNull('voided_at')
                ->count(),
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * A bank ledger of 100,000 in / 40,000 out / 5,000 in, built through the
     * API so the rows carry the balances the endpoint actually wrote.
     *
     * @return array{0: CashTransaction, 1: CashTransaction, 2: CashTransaction}
     */
    private function threeRowLedger(): array
    {
        return [
            $this->deposit(100_000, 'bank', Carbon::today()->subDays(30)),
            $this->withdraw(40_000, 'bank', Carbon::today()->subDays(20)),
            $this->deposit(5_000, 'bank', Carbon::today()->subDays(10)),
        ];
    }

    private function deposit(float $amount, string $account, ?Carbon $on = null): CashTransaction
    {
        $this->postJson("/api/portfolios/{$this->portfolio->id}/cash", [
            'action' => 'deposit',
            'account' => $account,
            'amount' => $amount,
            'transacted_on' => ($on ?? Carbon::today())->toDateString(),
        ])->assertCreated();

        return $this->lastTransaction();
    }

    private function withdraw(float $amount, string $account, ?Carbon $on = null): CashTransaction
    {
        $this->postJson("/api/portfolios/{$this->portfolio->id}/cash", [
            'action' => 'withdraw',
            'account' => $account,
            'amount' => $amount,
            'transacted_on' => ($on ?? Carbon::today())->toDateString(),
        ])->assertCreated();

        return $this->lastTransaction();
    }

    private function lastTransaction(): CashTransaction
    {
        return CashTransaction::where('portfolio_id', $this->portfolio->id)
            ->orderByDesc('id')
            ->firstOrFail();
    }
}
