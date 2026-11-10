<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Portfolio;
use App\Models\PortfolioHolding;
use App\Models\PortfolioTransaction;
use App\Models\Sector;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * HTTP coverage for `/holdings`, `/transactions` and `/rebuild`.
 *
 * The invariant under test is that a position is a function of the ledger:
 * a trade folds into the weighted average through `HoldingCalculator`, an
 * oversell is refused as the user's problem rather than a 500, and `/rebuild`
 * can always reproduce every position from the transactions alone.
 */
final class PortfolioHoldingTest extends FeatureTestCase
{
    private User $user;

    private Portfolio $portfolio;

    private Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->portfolio = Portfolio::factory()->forUser($this->user)->default()->create();
        $this->stock = Stock::factory()
            ->symbol('NABIL')
            ->inSector(Sector::factory()->named('Commercial Bank')->create())
            ->price(600)
            ->create();

        Sanctum::actingAs($this->user);
    }

    // ------------------------------------------------------------------
    // index
    // ------------------------------------------------------------------

    #[Test]
    public function it_returns_an_empty_breakdown_and_a_zeroed_summary(): void
    {
        $response = $this->getJson("/api/portfolios/{$this->portfolio->id}/holdings")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.holdings_count', 0)
            ->assertJsonStructure([
                'meta' => [
                    'total_invested', 'total_value', 'unrealized_gain', 'realized_gain',
                    'investable_cash', 'net_worth', 'net_worth_components' => [
                        'equity_market_value', 'investable_cash', 'settled_placements',
                    ],
                    'unpriced_symbols', 'sector_allocation', 'concentration',
                ],
            ]);

        $this->assertJsonNumber($response, 'meta.total_value', 0);
        $this->assertJsonNumber($response, 'meta.net_worth', 0);
    }

    #[Test]
    public function it_marks_holdings_at_the_latest_close(): void
    {
        PortfolioHolding::factory()
            ->for($this->portfolio)
            ->for($this->stock)
            ->position(100, 500)
            ->create();

        $response = $this->getJson("/api/portfolios/{$this->portfolio->id}/holdings")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.symbol', 'NABIL')
            ->assertJsonPath('data.0.sector', 'Commercial Bank')
            ->assertJsonPath('data.0.is_fund', false);

        $this->assertJsonNumber($response, 'data.0.invested', 50_000);
        $this->assertJsonNumber($response, 'data.0.current_value', 60_000);
        $this->assertJsonNumber($response, 'data.0.unrealized_gain', 10_000);
        $this->assertJsonNumber($response, 'data.0.unrealized_gain_percent', 20);
        $this->assertJsonNumber($response, 'data.0.weight_percent', 100);
    }

    #[Test]
    public function an_unpriced_holding_is_named_rather_than_valued_at_cost(): void
    {
        $unpriced = Stock::factory()->symbol('NEWCO')->unpriced()->create();

        PortfolioHolding::factory()
            ->for($this->portfolio)
            ->for($unpriced)
            ->position(50, 200)
            ->create();

        $response = $this->getJson("/api/portfolios/{$this->portfolio->id}/holdings")
            ->assertOk()
            ->assertJsonPath('data.0.current_value', null)
            ->assertJsonPath('data.0.unrealized_gain', null)
            ->assertJsonPath('meta.unpriced_symbols', ['NEWCO']);

        // Cost is still reported; only *value* is withheld, so a 0% gain is
        // never fabricated from cost.
        $this->assertJsonNumber($response, 'meta.total_invested', 10_000);
        $this->assertJsonNumber($response, 'meta.total_value', 0);
        $this->assertJsonNumber($response, 'meta.unrealized_gain', 0);
    }

    #[Test]
    public function sold_out_positions_drop_out_of_the_breakdown(): void
    {
        PortfolioHolding::factory()
            ->for($this->portfolio)
            ->for($this->stock)
            ->closed()
            ->create();

        $this->getJson("/api/portfolios/{$this->portfolio->id}/holdings")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.holdings_count', 0);
    }

    // ------------------------------------------------------------------
    // store — recording a trade
    // ------------------------------------------------------------------

    #[Test]
    public function it_records_a_buy_and_capitalises_the_fees_into_average_cost(): void
    {
        $response = $this->postJson("/api/portfolios/{$this->portfolio->id}/transactions", [
            'symbol' => 'nabil',
            'type' => 'buy',
            'quantity' => 100,
            'price' => 500,
            'commission' => 500,
            'other_fees' => 100,
            'transacted_on' => Carbon::today()->subDays(5)->toDateString(),
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'Buy of 100 NABIL recorded.');

        // (100 × 500 + 600) / 100
        $this->assertJsonNumber($response, 'data.holdings.0.average_cost', 506);

        $holding = PortfolioHolding::where('portfolio_id', $this->portfolio->id)->sole();

        $this->assertSame(100.0, (float) $holding->quantity);
        $this->assertSame(506.0, (float) $holding->average_cost);
        $this->assertSame(600.0, (float) $holding->total_fees);
        $this->assertDatabaseCount('portfolio_transactions', 1);
    }

    #[Test]
    public function a_second_buy_folds_into_the_weighted_average(): void
    {
        $this->buy(100, 400);
        $this->buy(100, 600);

        $holding = PortfolioHolding::where('portfolio_id', $this->portfolio->id)->sole();

        $this->assertSame(200.0, (float) $holding->quantity);
        $this->assertSame(500.0, (float) $holding->average_cost);
    }

    #[Test]
    public function selling_realises_the_gain_and_reduces_the_position(): void
    {
        $this->buy(100, 400);

        $this->postJson("/api/portfolios/{$this->portfolio->id}/transactions", [
            'symbol' => 'NABIL',
            'type' => 'sell',
            'quantity' => 40,
            'price' => 700,
            'transacted_on' => Carbon::today()->toDateString(),
        ])->assertCreated();

        $holding = PortfolioHolding::where('portfolio_id', $this->portfolio->id)->sole();

        $this->assertSame(60.0, (float) $holding->quantity);
        // 40 × (700 − 400)
        $this->assertSame(12_000.0, (float) $holding->realized_gain);
    }

    #[Test]
    public function it_refuses_to_oversell_a_position(): void
    {
        $this->buy(10, 400);

        $this->postJson("/api/portfolios/{$this->portfolio->id}/transactions", [
            'symbol' => 'NABIL',
            'type' => 'sell',
            'quantity' => 50,
            'price' => 700,
            'transacted_on' => Carbon::today()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (?string $message): bool => is_string($message)
                && str_contains($message, 'Cannot sell 50 units of NABIL'));

        $this->assertSame(
            10.0,
            (float) PortfolioHolding::where('portfolio_id', $this->portfolio->id)->sole()->quantity,
        );
        $this->assertDatabaseCount('portfolio_transactions', 1);
    }

    #[Test]
    public function a_bonus_issue_spreads_the_same_cost_over_more_shares(): void
    {
        $this->buy(100, 500);

        $this->postJson("/api/portfolios/{$this->portfolio->id}/transactions", [
            'symbol' => 'NABIL',
            'type' => 'bonus',
            'quantity' => 25,
            'price' => 0,
            'transacted_on' => Carbon::today()->toDateString(),
        ])->assertCreated();

        $holding = PortfolioHolding::where('portfolio_id', $this->portfolio->id)->sole();

        $this->assertSame(125.0, (float) $holding->quantity);
        $this->assertSame(400.0, (float) $holding->average_cost);
    }

    #[Test]
    public function settling_a_buy_debits_the_cash_account(): void
    {
        $this->postJson("/api/portfolios/{$this->portfolio->id}/cash", [
            'action' => 'deposit',
            'account' => 'bank',
            'amount' => 100_000,
        ])->assertCreated();

        $this->postJson("/api/portfolios/{$this->portfolio->id}/transactions", [
            'symbol' => 'NABIL',
            'type' => 'buy',
            'quantity' => 100,
            'price' => 500,
            'commission' => 500,
            'transacted_on' => Carbon::today()->toDateString(),
            'settle_cash' => true,
            'cash_account' => 'bank',
        ])->assertCreated();

        $this->assertSame(49_500.0, (float) $this->portfolio->fresh()->bank_balance);
        $this->assertDatabaseHas('cash_transactions', [
            'portfolio_id' => $this->portfolio->id,
            'type' => 'trade_debit',
            'amount' => -50500.00,
        ]);
    }

    #[Test]
    public function an_unfundable_settlement_rolls_the_whole_trade_back(): void
    {
        $this->postJson("/api/portfolios/{$this->portfolio->id}/cash", [
            'action' => 'deposit',
            'account' => 'bank',
            'amount' => 1_000,
        ])->assertCreated();

        $this->postJson("/api/portfolios/{$this->portfolio->id}/transactions", [
            'symbol' => 'NABIL',
            'type' => 'buy',
            'quantity' => 100,
            'price' => 500,
            'transacted_on' => Carbon::today()->toDateString(),
            'settle_cash' => true,
            'cash_account' => 'bank',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (?string $message): bool => is_string($message)
                && str_contains($message, 'Insufficient bank balance'));

        // Neither the trade nor the position survives a refused settlement.
        $this->assertDatabaseCount('portfolio_transactions', 0);
        $this->assertDatabaseCount('portfolio_holdings', 0);
        $this->assertSame(1_000.0, (float) $this->portfolio->fresh()->bank_balance);
    }

    // ------------------------------------------------------------------
    // store — validation
    // ------------------------------------------------------------------

    #[Test]
    public function it_rejects_a_transaction_with_nothing_filled_in(): void
    {
        $this->postJson("/api/portfolios/{$this->portfolio->id}/transactions", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['symbol', 'type', 'quantity', 'price', 'transacted_on']);
    }

    #[Test]
    public function it_rejects_an_unknown_ticker(): void
    {
        $this->postJson("/api/portfolios/{$this->portfolio->id}/transactions", [
            'symbol' => 'GHOST',
            'type' => 'buy',
            'quantity' => 1,
            'price' => 1,
            'transacted_on' => Carbon::today()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['symbol'])
            ->assertJsonPath(
                'errors.symbol.0',
                'That ticker is not in the stock list yet. Run the NEPSE scrape, or add the stock first.',
            );
    }

    #[Test]
    public function it_rejects_a_future_dated_or_pre_nepse_trade(): void
    {
        $this->postJson("/api/portfolios/{$this->portfolio->id}/transactions", [
            'symbol' => 'NABIL',
            'type' => 'buy',
            'quantity' => 1,
            'price' => 1,
            'transacted_on' => Carbon::tomorrow()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.transacted_on.0', 'A transaction cannot be dated in the future.');

        $this->postJson("/api/portfolios/{$this->portfolio->id}/transactions", [
            'symbol' => 'NABIL',
            'type' => 'buy',
            'quantity' => 1,
            'price' => 1,
            'transacted_on' => '1990-01-01',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['transacted_on']);
    }

    #[Test]
    public function it_rejects_an_unknown_transaction_type_and_a_zero_quantity(): void
    {
        $this->postJson("/api/portfolios/{$this->portfolio->id}/transactions", [
            'symbol' => 'NABIL',
            'type' => 'short',
            'quantity' => 0,
            'price' => 100,
            'transacted_on' => Carbon::today()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type', 'quantity']);
    }

    // ------------------------------------------------------------------
    // update / destroy
    // ------------------------------------------------------------------

    #[Test]
    public function it_adjusts_a_position_directly_for_an_opening_balance(): void
    {
        $holding = PortfolioHolding::factory()
            ->for($this->portfolio)
            ->for($this->stock)
            ->position(100, 500)
            ->create();

        $response = $this->patchJson(
            "/api/portfolios/{$this->portfolio->id}/holdings/{$holding->id}",
            ['quantity' => 250, 'average_cost' => 420, 'notes' => 'Opening balance correction.'],
        )
            ->assertOk()
            ->assertJsonPath('message', 'Holding updated.');

        $this->assertJsonNumber($response, 'data.holdings.0.quantity', 250);
        $this->assertJsonNumber($response, 'data.holdings.0.average_cost', 420);
    }

    #[Test]
    public function it_rejects_a_negative_position_adjustment(): void
    {
        $holding = PortfolioHolding::factory()
            ->for($this->portfolio)
            ->for($this->stock)
            ->create();

        $this->patchJson(
            "/api/portfolios/{$this->portfolio->id}/holdings/{$holding->id}",
            ['quantity' => -5, 'average_cost' => -1],
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quantity', 'average_cost']);
    }

    #[Test]
    public function deleting_a_holding_also_removes_its_ledger_entries(): void
    {
        $this->buy(100, 400);

        $holding = PortfolioHolding::where('portfolio_id', $this->portfolio->id)->sole();

        $this->deleteJson("/api/portfolios/{$this->portfolio->id}/holdings/{$holding->id}")
            ->assertOk()
            ->assertJsonPath('message', 'NABIL removed from the portfolio.');

        // Otherwise a later /rebuild would resurrect the position.
        $this->assertDatabaseCount('portfolio_holdings', 0);
        $this->assertDatabaseCount('portfolio_transactions', 0);
    }

    // ------------------------------------------------------------------
    // rebuild
    // ------------------------------------------------------------------

    #[Test]
    public function rebuild_reproduces_every_position_from_the_ledger_alone(): void
    {
        $other = Stock::factory()->symbol('UPPER')->price(300)->create();

        PortfolioTransaction::factory()
            ->for($this->portfolio)
            ->for($this->stock)
            ->buy(100, 400, Carbon::today()->subDays(60))
            ->create();

        PortfolioTransaction::factory()
            ->for($this->portfolio)
            ->for($this->stock)
            ->buy(100, 600, Carbon::today()->subDays(30))
            ->create();

        PortfolioTransaction::factory()
            ->for($this->portfolio)
            ->for($other)
            ->buy(200, 250, Carbon::today()->subDays(10))
            ->create();

        // No holdings rows at all yet — the ledger is the only truth.
        $this->assertDatabaseCount('portfolio_holdings', 0);

        $this->postJson("/api/portfolios/{$this->portfolio->id}/rebuild")
            ->assertOk()
            ->assertJsonPath('message', 'Rebuilt 2 positions from the transaction history.')
            ->assertJsonCount(2, 'data.holdings');

        $nabil = PortfolioHolding::where('portfolio_id', $this->portfolio->id)
            ->where('stock_id', $this->stock->id)
            ->sole();

        $this->assertSame(200.0, (float) $nabil->quantity);
        $this->assertSame(500.0, (float) $nabil->average_cost);
    }

    #[Test]
    public function rebuild_corrects_a_position_that_drifted_from_its_ledger(): void
    {
        PortfolioTransaction::factory()
            ->for($this->portfolio)
            ->for($this->stock)
            ->buy(100, 400, Carbon::today()->subDays(20))
            ->create();

        // A position that disagrees with the ledger — what a bad import or a
        // hand-patched row leaves behind.
        PortfolioHolding::factory()
            ->for($this->portfolio)
            ->for($this->stock)
            ->position(9_999, 1)
            ->create();

        $this->postJson("/api/portfolios/{$this->portfolio->id}/rebuild")->assertOk();

        $holding = PortfolioHolding::where('portfolio_id', $this->portfolio->id)->sole();

        $this->assertSame(100.0, (float) $holding->quantity);
        $this->assertSame(400.0, (float) $holding->average_cost);
    }

    #[Test]
    public function rebuild_on_an_empty_portfolio_is_a_no_op(): void
    {
        $this->postJson("/api/portfolios/{$this->portfolio->id}/rebuild")
            ->assertOk()
            ->assertJsonPath('message', 'Rebuilt 0 positions from the transaction history.')
            ->assertJsonCount(0, 'data.holdings');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function buy(float $quantity, float $price): void
    {
        $this->postJson("/api/portfolios/{$this->portfolio->id}/transactions", [
            'symbol' => 'NABIL',
            'type' => 'buy',
            'quantity' => $quantity,
            'price' => $price,
            'transacted_on' => Carbon::today()->subDays(2)->toDateString(),
        ])->assertCreated();
    }
}
