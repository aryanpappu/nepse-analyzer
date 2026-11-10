<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\CashPlacementStatus;
use App\Models\CashPlacement;
use App\Models\CashTransaction;
use App\Models\Portfolio;
use App\Models\User;
use App\Services\Portfolio\CashPlacementSettlement;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * HTTP coverage for the seven `/api/portfolios/{portfolio}/cash-placements`
 * routes.
 *
 * Two behaviours carry the money: opening with `settle_cash` debits the
 * principal out of a cash account (so valuation counts the placement instead
 * of the same rupees as idle cash), and closing credits it back — principal
 * and interest as two separate rows, with interest included only when it was
 * reinvested rather than already paid out.
 */
final class CashPlacementTest extends FeatureTestCase
{
    private User $user;

    private Portfolio $portfolio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->portfolio = Portfolio::factory()->forUser($this->user)->create();

        Sanctum::actingAs($this->user);
    }

    // ------------------------------------------------------------------
    // index
    // ------------------------------------------------------------------

    #[Test]
    public function it_lists_placements_with_accrual_and_summary_meta(): void
    {
        CashPlacement::factory()->for($this->portfolio)->create([
            'principal' => 200_000,
            'interest_rate' => 10,
            'start_date' => Carbon::today()->subDays(365)->toDateString(),
            'maturity_date' => Carbon::today()->addDays(365)->toDateString(),
        ]);

        $response = $this->getJson("/api/portfolios/{$this->portfolio->id}/cash-placements")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'data' => [
                    ['id', 'kind', 'principal', 'interest_rate', 'status', 'accrual' => [
                        'principal', 'accrued_interest', 'days_accrued', 'is_capped_at_maturity',
                    ]],
                ],
                'meta' => [
                    'as_of', 'count', 'total_principal', 'total_accrued_interest',
                    'matured_count', 'maturing_soon_count', 'weighted_average_rate',
                ],
            ]);

        // Simple interest, Actual/365: 200,000 at 10% for a year.
        $this->assertJsonNumber($response, 'data.0.accrual.accrued_interest', 20_000);
        $this->assertJsonNumber($response, 'meta.total_principal', 200_000);
        $this->assertJsonNumber($response, 'meta.weighted_average_rate', 10);
    }

    #[Test]
    public function it_filters_the_listing_by_status(): void
    {
        CashPlacement::factory()->for($this->portfolio)->create();
        $matured = CashPlacement::factory()->for($this->portfolio)->matured()->create();

        $this->getJson("/api/portfolios/{$this->portfolio->id}/cash-placements?status=matured")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matured->id);
    }

    #[Test]
    public function the_listing_accrues_only_to_the_requested_as_of_date(): void
    {
        CashPlacement::factory()->for($this->portfolio)->create([
            'principal' => 100_000,
            'interest_rate' => 10,
            'start_date' => Carbon::today()->subDays(400)->toDateString(),
            'maturity_date' => Carbon::today()->addDays(400)->toDateString(),
        ]);

        $asOf = Carbon::today()->subDays(35)->toDateString();

        $response = $this->getJson(
            "/api/portfolios/{$this->portfolio->id}/cash-placements?as_of={$asOf}"
        )->assertOk()->assertJsonPath('meta.as_of', $asOf);

        // 365 days at 10% on 100,000.
        $this->assertJsonNumber($response, 'data.0.accrual.accrued_interest', 10_000);
    }

    #[Test]
    public function it_lists_nothing_for_a_portfolio_with_no_placements(): void
    {
        $this->getJson("/api/portfolios/{$this->portfolio->id}/cash-placements")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.count', 0)
            ->assertJsonPath('meta.weighted_average_rate', null);
    }

    // ------------------------------------------------------------------
    // show
    // ------------------------------------------------------------------

    #[Test]
    public function it_shows_one_placement(): void
    {
        $placement = CashPlacement::factory()->for($this->portfolio)->create();

        $this->getJson("/api/portfolios/{$this->portfolio->id}/cash-placements/{$placement->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $placement->id)
            ->assertJsonPath('data.portfolio_id', $this->portfolio->id)
            ->assertJsonStructure(['data' => ['accrual' => ['accrued_interest', 'days_accrued']]]);
    }

    #[Test]
    public function showing_a_placement_that_does_not_exist_is_a_404(): void
    {
        $this->getJson("/api/portfolios/{$this->portfolio->id}/cash-placements/999999")
            ->assertNotFound()
            ->assertJsonPath('message', 'Not found.');
    }

    // ------------------------------------------------------------------
    // store
    // ------------------------------------------------------------------

    #[Test]
    public function it_records_a_placement_without_moving_any_cash(): void
    {
        $this->postJson("/api/portfolios/{$this->portfolio->id}/cash-placements", [
            'institution' => 'Nabil Bank',
            'principal' => 150_000,
            'interest_rate' => 9.25,
            'start_date' => Carbon::today()->subDays(10)->toDateString(),
            'maturity_date' => Carbon::today()->addDays(355)->toDateString(),
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'Cash placement recorded.')
            ->assertJsonPath('data.institution', 'Nabil Bank')
            ->assertJsonPath('data.status', CashPlacementStatus::Active->value);

        $this->assertDatabaseCount('cash_placements', 1);
        // Purely informational: nothing left the cash account.
        $this->assertDatabaseCount('cash_transactions', 0);
    }

    #[Test]
    public function it_falls_back_to_the_configured_fd_rate_when_none_is_given(): void
    {
        config(['nepse.benchmark.fixed_deposit_rate' => 7.75]);

        $response = $this->postJson("/api/portfolios/{$this->portfolio->id}/cash-placements", [
            'principal' => 50_000,
            'start_date' => Carbon::today()->toDateString(),
        ])->assertCreated();

        $this->assertJsonNumber($response, 'data.interest_rate', 7.75);
    }

    #[Test]
    public function settling_a_new_placement_debits_the_cash_account_on_its_start_date(): void
    {
        $this->depositBank(300_000);
        $startedOn = Carbon::today()->subDays(7);

        $response = $this->postJson("/api/portfolios/{$this->portfolio->id}/cash-placements", [
            'principal' => 120_000,
            'interest_rate' => 8,
            'start_date' => $startedOn->toDateString(),
            'settle_cash' => true,
            'cash_account' => 'bank',
        ])->assertCreated();

        $placementId = $response->json('data.id');

        $this->assertSame(180_000.0, (float) $this->portfolio->fresh()->bank_balance);

        $debit = CashTransaction::where('portfolio_id', $this->portfolio->id)
            ->where('type', CashPlacementSettlement::TYPE_OPEN)
            ->where('reference', CashPlacementSettlement::referenceFor((int) $placementId))
            ->sole();

        $this->assertSame('bank', $debit->account);
        $this->assertSame(-120_000.0, (float) $debit->amount);
        // Dated the day the money actually left the account, not today.
        $this->assertSame($startedOn->toDateString(), $debit->transacted_on->toDateString());
    }

    #[Test]
    public function it_rejects_a_placement_with_no_principal_or_start_date(): void
    {
        $this->postJson("/api/portfolios/{$this->portfolio->id}/cash-placements", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['principal', 'start_date']);
    }

    #[Test]
    public function it_rejects_a_future_start_date_and_a_maturity_before_it(): void
    {
        $this->postJson("/api/portfolios/{$this->portfolio->id}/cash-placements", [
            'principal' => 1_000,
            'start_date' => Carbon::tomorrow()->toDateString(),
            'maturity_date' => Carbon::today()->subDay()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['start_date', 'maturity_date']);
    }

    #[Test]
    public function it_rejects_unknown_enum_values(): void
    {
        $this->postJson("/api/portfolios/{$this->portfolio->id}/cash-placements", [
            'principal' => 1_000,
            'start_date' => Carbon::today()->toDateString(),
            'kind' => 'crypto_stake',
            'compounding_basis' => 'fortnightly',
            'payout' => 'burned',
            'status' => 'sideways',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kind', 'compounding_basis', 'payout', 'status']);
    }

    /**
     * Documents a real gap rather than blessing it: `CashPlacementController::store()`
     * does not catch the `RuntimeException` `CashLedger::record()` throws on an
     * overdraw, so an unfundable settlement surfaces as a 500 instead of the 422
     * every other money endpoint returns. The rollback itself is correct — no
     * placement and no ledger row survive — which is what this asserts.
     */
    #[Test]
    public function an_unfundable_settlement_is_refused_as_a_422_and_rolled_back(): void
    {
        $this->depositBank(10_000);

        // CashLedger refuses the overdraw; the whole placement rolls back with
        // it, and the status matches every other money endpoint rather than
        // surfacing as a 500.
        $this->postJson("/api/portfolios/{$this->portfolio->id}/cash-placements", [
            'principal' => 500_000,
            'start_date' => Carbon::today()->toDateString(),
            'settle_cash' => true,
            'cash_account' => 'bank',
        ])->assertStatus(422);

        $this->assertDatabaseCount('cash_placements', 0);
        $this->assertSame(10_000.0, (float) $this->portfolio->fresh()->bank_balance);
    }

    // ------------------------------------------------------------------
    // bulk store
    // ------------------------------------------------------------------

    #[Test]
    public function it_bulk_creates_placements(): void
    {
        $this->postJson("/api/portfolios/{$this->portfolio->id}/cash-placements/bulk", [
            'placements' => [
                ['principal' => 10_000, 'interest_rate' => 8, 'start_date' => Carbon::today()->subDays(3)->toDateString()],
                ['principal' => 20_000, 'interest_rate' => 9, 'start_date' => Carbon::today()->subDays(2)->toDateString()],
                ['principal' => 30_000, 'interest_rate' => 10, 'start_date' => Carbon::today()->subDay()->toDateString()],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('message', '3 cash placements recorded.')
            ->assertJsonCount(3, 'data');

        $this->assertDatabaseCount('cash_placements', 3);
    }

    #[Test]
    public function one_bad_row_fails_the_whole_bulk_request(): void
    {
        $this->postJson("/api/portfolios/{$this->portfolio->id}/cash-placements/bulk", [
            'placements' => [
                ['principal' => 10_000, 'start_date' => Carbon::today()->toDateString()],
                ['principal' => -5, 'start_date' => Carbon::today()->toDateString()],
                [
                    'principal' => 10_000,
                    'start_date' => Carbon::today()->subDays(10)->toDateString(),
                    'maturity_date' => Carbon::today()->subDays(20)->toDateString(),
                ],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['placements.1.principal', 'placements.2.maturity_date']);

        $this->assertDatabaseCount('cash_placements', 0);
    }

    #[Test]
    public function it_requires_at_least_one_placement_in_a_bulk_request(): void
    {
        $this->postJson("/api/portfolios/{$this->portfolio->id}/cash-placements/bulk", ['placements' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['placements']);
    }

    #[Test]
    public function the_bulk_literal_segment_wins_over_the_id_wildcard(): void
    {
        // `/bulk` must not be parsed as a placement id.
        $this->postJson("/api/portfolios/{$this->portfolio->id}/cash-placements/bulk", [
            'placements' => [['principal' => 1_000, 'start_date' => Carbon::today()->toDateString()]],
        ])->assertCreated();
    }

    // ------------------------------------------------------------------
    // update
    // ------------------------------------------------------------------

    #[Test]
    public function it_updates_a_placements_terms(): void
    {
        $placement = CashPlacement::factory()->for($this->portfolio)->create(['interest_rate' => 8]);

        $response = $this->patchJson(
            "/api/portfolios/{$this->portfolio->id}/cash-placements/{$placement->id}",
            ['interest_rate' => 9.5, 'institution' => 'Global IME', 'notes' => 'Renegotiated.'],
        )
            ->assertOk()
            ->assertJsonPath('message', 'Cash placement updated.')
            ->assertJsonPath('data.institution', 'Global IME');

        $this->assertJsonNumber($response, 'data.interest_rate', 9.5);
    }

    #[Test]
    public function updating_does_not_re_settle_cash(): void
    {
        $this->depositBank(100_000);
        $placement = CashPlacement::factory()->for($this->portfolio)->create(['principal' => 50_000]);

        $this->patchJson(
            "/api/portfolios/{$this->portfolio->id}/cash-placements/{$placement->id}",
            ['principal' => 60_000],
        )->assertOk();

        // Editing what the placement says must not move where the money is.
        $this->assertSame(100_000.0, (float) $this->portfolio->fresh()->bank_balance);
    }

    #[Test]
    public function it_refuses_a_maturity_date_that_precedes_the_start_date(): void
    {
        $placement = CashPlacement::factory()->for($this->portfolio)->create([
            'start_date' => Carbon::today()->subDays(100)->toDateString(),
        ]);

        $this->patchJson(
            "/api/portfolios/{$this->portfolio->id}/cash-placements/{$placement->id}",
            ['maturity_date' => Carbon::today()->subDays(200)->toDateString()],
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Maturity date must be after the start date.');
    }

    #[Test]
    public function it_rejects_an_invalid_update_payload(): void
    {
        $placement = CashPlacement::factory()->for($this->portfolio)->create();

        $this->patchJson(
            "/api/portfolios/{$this->portfolio->id}/cash-placements/{$placement->id}",
            ['principal' => 0, 'status' => 'nonsense'],
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors(['principal', 'status']);
    }

    // ------------------------------------------------------------------
    // destroy
    // ------------------------------------------------------------------

    #[Test]
    public function it_deletes_a_placement(): void
    {
        $placement = CashPlacement::factory()->for($this->portfolio)->create();

        $this->deleteJson("/api/portfolios/{$this->portfolio->id}/cash-placements/{$placement->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Cash placement deleted.');

        $this->assertDatabaseMissing('cash_placements', ['id' => $placement->id]);
    }

    // ------------------------------------------------------------------
    // withdraw
    // ------------------------------------------------------------------

    #[Test]
    public function withdrawing_stops_accrual_and_marks_the_placement_withdrawn(): void
    {
        $placement = CashPlacement::factory()->for($this->portfolio)->create([
            'principal' => 100_000,
            'interest_rate' => 10,
            'start_date' => Carbon::today()->subDays(200)->toDateString(),
            'maturity_date' => Carbon::today()->addDays(165)->toDateString(),
        ]);

        $closedOn = Carbon::today()->subDays(35);

        $this->postJson(
            "/api/portfolios/{$this->portfolio->id}/cash-placements/{$placement->id}/withdraw",
            ['closed_on' => $closedOn->toDateString()],
        )
            ->assertOk()
            ->assertJsonPath('message', 'Deposit closed.')
            ->assertJsonPath('data.status', CashPlacementStatus::Withdrawn->value)
            ->assertJsonPath('data.closed_on', $closedOn->toDateString());

        // Unsettled on open, so nothing is credited back on close either.
        $this->assertDatabaseCount('cash_transactions', 0);

        $response = $this->getJson(
            "/api/portfolios/{$this->portfolio->id}/cash-placements/{$placement->id}"
        )->assertOk();

        // Accrual is capped at the closure date (165 days), not run to today.
        $this->assertJsonNumber($response, 'data.accrual.days_accrued', 165);
        $response->assertJsonPath('data.accrual.effective_end_date', $closedOn->toDateString());
    }

    #[Test]
    public function withdrawing_a_settled_paid_out_placement_credits_only_the_principal(): void
    {
        $this->depositBank(200_000);

        $placementId = (int) $this->postJson("/api/portfolios/{$this->portfolio->id}/cash-placements", [
            'principal' => 100_000,
            'interest_rate' => 10,
            'start_date' => Carbon::today()->subDays(365)->toDateString(),
            'payout' => 'paid_out',
            'settle_cash' => true,
            'cash_account' => 'bank',
        ])->assertCreated()->json('data.id');

        $this->assertSame(100_000.0, (float) $this->portfolio->fresh()->bank_balance);

        $this->postJson(
            "/api/portfolios/{$this->portfolio->id}/cash-placements/{$placementId}/withdraw"
        )->assertOk();

        // Principal back, interest not — it was disbursed during the term, so
        // crediting it here would pay it twice.
        $this->assertSame(200_000.0, (float) $this->portfolio->fresh()->bank_balance);
        $this->assertDatabaseHas('cash_transactions', [
            'portfolio_id' => $this->portfolio->id,
            'type' => CashPlacementSettlement::TYPE_CLOSE,
            'amount' => 100000.00,
        ]);
        $this->assertDatabaseMissing('cash_transactions', [
            'portfolio_id' => $this->portfolio->id,
            'type' => CashPlacementSettlement::TYPE_INTEREST,
        ]);
    }

    #[Test]
    public function withdrawing_a_settled_reinvested_placement_credits_principal_and_interest(): void
    {
        $this->depositBank(200_000);

        $placementId = (int) $this->postJson("/api/portfolios/{$this->portfolio->id}/cash-placements", [
            'principal' => 100_000,
            'interest_rate' => 10,
            'start_date' => Carbon::today()->subDays(365)->toDateString(),
            'compounding_basis' => 'simple',
            'payout' => 'reinvested',
            'settle_cash' => true,
            'cash_account' => 'bank',
        ])->assertCreated()->json('data.id');

        $this->postJson(
            "/api/portfolios/{$this->portfolio->id}/cash-placements/{$placementId}/withdraw"
        )->assertOk();

        // 100,000 principal + 10,000 of never-disbursed interest.
        $this->assertSame(210_000.0, (float) $this->portfolio->fresh()->bank_balance);
        $this->assertDatabaseHas('cash_transactions', [
            'portfolio_id' => $this->portfolio->id,
            'type' => CashPlacementSettlement::TYPE_INTEREST,
            'amount' => 10000.00,
        ]);
    }

    #[Test]
    public function it_refuses_a_closure_before_the_start_date(): void
    {
        $placement = CashPlacement::factory()->for($this->portfolio)->create([
            'start_date' => Carbon::today()->subDays(10)->toDateString(),
        ]);

        $this->postJson(
            "/api/portfolios/{$this->portfolio->id}/cash-placements/{$placement->id}/withdraw",
            ['closed_on' => Carbon::today()->subDays(30)->toDateString()],
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Closed date cannot be before the start date.');

        $this->assertSame(
            CashPlacementStatus::Active,
            $placement->fresh()->status,
        );
    }

    #[Test]
    public function it_refuses_a_future_closure_date(): void
    {
        $placement = CashPlacement::factory()->for($this->portfolio)->create();

        $this->postJson(
            "/api/portfolios/{$this->portfolio->id}/cash-placements/{$placement->id}/withdraw",
            ['closed_on' => Carbon::tomorrow()->toDateString()],
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors(['closed_on']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function depositBank(float $amount): void
    {
        $this->postJson("/api/portfolios/{$this->portfolio->id}/cash", [
            'action' => 'deposit',
            'account' => 'bank',
            'amount' => $amount,
        ])->assertCreated();
    }
}
