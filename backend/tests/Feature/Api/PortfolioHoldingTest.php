<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\CashTransaction;
use App\Models\Portfolio;
use App\Models\PortfolioHolding;
use App\Models\PortfolioTransaction;
use App\Models\Sector;
use App\Models\Stock;
use App\Models\User;
use App\Services\Portfolio\CashLedger;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
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
    public function patching_a_holding_edits_notes_only(): void
    {
        $this->buy(100, 400);
        $holding = PortfolioHolding::where('portfolio_id', $this->portfolio->id)->sole();

        $this->patchJson(
            "/api/portfolios/{$this->portfolio->id}/holdings/{$holding->id}",
            ['notes' => 'Long-term core.'],
        )->assertOk()->assertJsonPath('message', 'Holding updated.');

        $holding->refresh();
        $this->assertSame('Long-term core.', $holding->notes);
        $this->assertSame(100.0, (float) $holding->quantity);
    }

    #[Test]
    public function it_refuses_to_overwrite_a_position_outside_the_ledger(): void
    {
        $this->buy(100, 400);
        $holding = PortfolioHolding::where('portfolio_id', $this->portfolio->id)->sole();

        // A patched quantity was silently undone by the next rebuild, so it is
        // refused rather than accepted and later lost.
        $this->patchJson(
            "/api/portfolios/{$this->portfolio->id}/holdings/{$holding->id}",
            ['quantity' => 250, 'average_cost' => 420],
        )->assertStatus(422)->assertJsonValidationErrors(['quantity']);

        $this->assertSame(100.0, (float) $holding->refresh()->quantity);
    }

    #[Test]
    public function a_backdated_buy_reprices_a_later_sell_exactly_as_rebuild_would(): void
    {
        $this->trade('buy', 100, 400, 30);
        $this->trade('sell', 100, 600, 10);

        // Recorded after the sell, dated before it: it is part of the cost of
        // the units sold, not a fresh position opened afterwards.
        $this->trade('buy', 100, 200, 20);

        $holding = PortfolioHolding::where('portfolio_id', $this->portfolio->id)->sole();
        $recorded = [(float) $holding->quantity, (float) $holding->average_cost, (float) $holding->realized_gain];

        $this->assertSame(100.0, $recorded[0]);
        $this->assertSame(300.0, $recorded[1]);
        $this->assertSame(30000.0, $recorded[2]);

        $sell = PortfolioTransaction::where('type', 'sell')->sole();
        $this->assertSame(30000.0, (float) $sell->realized_gain);

        $this->postJson("/api/portfolios/{$this->portfolio->id}/rebuild")->assertOk();
        $holding->refresh();

        $this->assertSame($recorded, [(float) $holding->quantity, (float) $holding->average_cost, (float) $holding->realized_gain]);
    }

    #[Test]
    public function a_sell_dated_before_the_units_were_held_is_refused(): void
    {
        $this->trade('buy', 100, 400, 10);

        $this->postJson("/api/portfolios/{$this->portfolio->id}/transactions", [
            'symbol' => 'NABIL', 'type' => 'sell', 'quantity' => 50, 'price' => 500,
            'transacted_on' => Carbon::today()->subDays(20)->toDateString(),
        ])->assertStatus(422)->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'held 0'));

        $this->assertDatabaseCount('portfolio_transactions', 1);
        $this->assertSame(100.0, (float) PortfolioHolding::where('portfolio_id', $this->portfolio->id)->sole()->quantity);
    }

    #[Test]
    public function a_settled_trade_is_dated_and_linked_and_removing_it_voids_the_cash(): void
    {
        app(CashLedger::class)->deposit($this->portfolio, 'bank', 100000, null, null, Carbon::today()->subDays(40));

        $this->postJson("/api/portfolios/{$this->portfolio->id}/transactions", [
            'symbol' => 'NABIL', 'type' => 'buy', 'quantity' => 100, 'price' => 400,
            'transacted_on' => Carbon::today()->subDays(30)->toDateString(),
            'settle_cash' => true, 'cash_account' => 'bank',
        ])->assertCreated();

        $trade = PortfolioTransaction::sole();
        $cash = CashTransaction::where('type', 'trade_debit')->sole();

        $this->assertSame($trade->id, $cash->portfolio_transaction_id);
        $this->assertSame(Carbon::today()->subDays(30)->toDateString(), Carbon::parse($cash->transacted_on)->toDateString());
        $this->assertSame(60000.0, (float) $this->portfolio->fresh()->bank_balance);

        // The settlement follows the trade; editing it by hand is refused.
        try {
            app(CashLedger::class)->amend($this->portfolio, $cash, ['amount' => 1]);
            $this->fail('Amending a trade settlement should be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('settles a recorded trade', $e->getMessage());
        }

        $holding = PortfolioHolding::where('portfolio_id', $this->portfolio->id)->sole();
        $this->deleteJson("/api/portfolios/{$this->portfolio->id}/holdings/{$holding->id}")->assertOk();

        $this->assertNotNull($cash->fresh()->voided_at);
        $this->assertSame(100000.0, (float) $this->portfolio->fresh()->bank_balance);
    }

    #[Test]
    public function a_backdated_settlement_cannot_overdraw_the_account_on_its_own_date(): void
    {
        $ledger = app(CashLedger::class);
        $ledger->deposit($this->portfolio, 'bank', 50000, null, null, Carbon::today()->subDays(30));
        $ledger->deposit($this->portfolio, 'bank', 50000, null, null, Carbon::today()->subDays(10));

        // Today's balance covers it, but on the trade date only the first
        // deposit had arrived.
        $this->postJson("/api/portfolios/{$this->portfolio->id}/transactions", [
            'symbol' => 'NABIL', 'type' => 'buy', 'quantity' => 100, 'price' => 800,
            'transacted_on' => Carbon::today()->subDays(20)->toDateString(),
            'settle_cash' => true, 'cash_account' => 'bank',
        ])->assertStatus(422)->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'overdrawn'));

        $this->assertDatabaseCount('portfolio_transactions', 0);
        $this->assertSame(100000.0, (float) $this->portfolio->fresh()->bank_balance);
    }

    #[Test]
    public function a_settlement_dated_before_the_ledger_begins_is_not_judged_against_it(): void
    {
        // An opening balance entered today says nothing about last month.
        app(CashLedger::class)->deposit($this->portfolio, 'bank', 100000);

        $this->postJson("/api/portfolios/{$this->portfolio->id}/transactions", [
            'symbol' => 'NABIL', 'type' => 'buy', 'quantity' => 100, 'price' => 800,
            'transacted_on' => Carbon::today()->subDays(20)->toDateString(),
            'settle_cash' => true, 'cash_account' => 'bank',
        ])->assertCreated();

        $this->assertSame(20000.0, (float) $this->portfolio->fresh()->bank_balance);
    }

    #[Test]
    public function an_imported_oversell_does_not_block_later_trades_on_the_scrip(): void
    {
        // An imported sell of a parcel the ledger never bought; rebuild clamps it.
        PortfolioTransaction::factory()->for($this->portfolio)->for($this->stock)
            ->create(['type' => 'sell', 'quantity' => 50, 'price' => 500, 'transacted_on' => Carbon::today()->subDays(30)]);

        $this->trade('buy', 100, 400, 5);

        $this->assertSame(100.0, (float) PortfolioHolding::where('portfolio_id', $this->portfolio->id)->sole()->quantity);
    }

    #[Test]
    public function removing_a_holding_voids_all_its_settlements_together(): void
    {
        app(CashLedger::class)->deposit($this->portfolio, 'bank', 50000, null, null, Carbon::today()->subDays(40));

        $settle = fn (string $type, float $qty, float $price, int $daysAgo) => $this->postJson(
            "/api/portfolios/{$this->portfolio->id}/transactions",
            [
                'symbol' => 'NABIL', 'type' => $type, 'quantity' => $qty, 'price' => $price,
                'transacted_on' => Carbon::today()->subDays($daysAgo)->toDateString(),
                'settle_cash' => true, 'cash_account' => 'bank',
            ],
        )->assertCreated();

        $settle('buy', 100, 400, 30);   // 50k -> 10k
        $settle('sell', 100, 600, 20);  // 10k -> 70k
        $settle('buy', 100, 650, 10);   // 70k -> 5k, paid from the sale

        // One at a time, voiding the sale's credit first would overdraw the
        // account in between; together the result is simply the deposit.
        $holding = PortfolioHolding::where('portfolio_id', $this->portfolio->id)->sole();
        $this->deleteJson("/api/portfolios/{$this->portfolio->id}/holdings/{$holding->id}")->assertOk();

        $this->assertSame(50000.0, (float) $this->portfolio->fresh()->bank_balance);
        $this->assertSame(3, CashTransaction::whereNotNull('voided_at')->count());
    }

    #[Test]
    public function a_lump_sum_dividend_settles_its_full_amount(): void
    {
        $this->buy(100, 400);

        $this->postJson("/api/portfolios/{$this->portfolio->id}/transactions", [
            'symbol' => 'NABIL', 'type' => 'dividend', 'quantity' => 1500, 'price' => 0,
            'transacted_on' => Carbon::today()->toDateString(),
            'settle_cash' => true, 'cash_account' => 'bank',
        ])->assertCreated();

        $this->assertSame(1500.0, (float) CashTransaction::where('type', 'trade_credit')->sole()->amount);
        $this->assertSame(1500.0, (float) PortfolioHolding::where('portfolio_id', $this->portfolio->id)->sole()->realized_gain);
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

    private function trade(string $type, float $quantity, float $price, int $daysAgo): void
    {
        $this->postJson("/api/portfolios/{$this->portfolio->id}/transactions", [
            'symbol' => 'NABIL',
            'type' => $type,
            'quantity' => $quantity,
            'price' => $price,
            'transacted_on' => Carbon::today()->subDays($daysAgo)->toDateString(),
        ])->assertCreated();
    }

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
