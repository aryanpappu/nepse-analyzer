<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\MarketIndex;
use App\Models\Portfolio;
use App\Models\PortfolioHolding;
use App\Models\PortfolioTransaction;
use App\Models\Sector;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use App\Services\Portfolio\PerformanceService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * HTTP coverage for `/performance`, `/benchmark` and `/attribution`.
 *
 * The rule these three share is that a benchmark is never reported without its
 * coverage: a partial index window can invert the verdict outright, so
 * `index_coverage_percent`, `index_simulation_complete`, `index_uncovered_dates`
 * and the prose note have to be on every response, and a figure that cannot be
 * computed honestly comes back `null` rather than `0`.
 */
final class PortfolioPerformanceTest extends FeatureTestCase
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
    // /performance
    // ------------------------------------------------------------------

    #[Test]
    public function it_reports_performance_for_an_empty_portfolio(): void
    {
        $this->getJson("/api/portfolios/{$this->portfolio->id}/performance")
            ->assertOk()
            ->assertJsonPath('data.basis', PerformanceService::BASIS_FISCAL)
            ->assertJsonPath('data.fixed_deposit_basis', 'equity_only')
            ->assertJsonCount(0, 'data.closed_trades')
            ->assertJsonStructure([
                'data' => ['basis', 'fixed_deposit_rate', 'periods', 'totals', 'closed_trades', 'by_stock'],
            ]);
    }

    #[Test]
    public function it_buckets_performance_by_fiscal_year_by_default_and_by_calendar_on_request(): void
    {
        $this->seedHistory();

        $this->getJson("/api/portfolios/{$this->portfolio->id}/performance")
            ->assertOk()
            ->assertJsonPath('data.basis', PerformanceService::BASIS_FISCAL)
            ->assertJsonPath('data.fiscal_year_note', fn (?string $note): bool => is_string($note)
                && str_contains($note, 'Shrawan'));

        $this->getJson("/api/portfolios/{$this->portfolio->id}/performance?basis=calendar")
            ->assertOk()
            ->assertJsonPath('data.basis', PerformanceService::BASIS_CALENDAR)
            ->assertJsonPath('data.fiscal_year_note', null);
    }

    #[Test]
    public function the_deposit_benchmark_rate_is_rebasable_per_request(): void
    {
        $this->seedHistory();

        $response = $this->getJson("/api/portfolios/{$this->portfolio->id}/performance?fd_rate=12.5")
            ->assertOk();

        $this->assertJsonNumber($response, 'data.fixed_deposit_rate', 12.5);
    }

    #[Test]
    public function it_rejects_an_unknown_basis_or_an_out_of_range_deposit_rate(): void
    {
        $this->getJson("/api/portfolios/{$this->portfolio->id}/performance?basis=quarterly")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['basis']);

        $this->getJson("/api/portfolios/{$this->portfolio->id}/performance?fd_rate=95")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fd_rate']);

        $this->getJson("/api/portfolios/{$this->portfolio->id}/performance?fd_rate=-1")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fd_rate']);
    }

    #[Test]
    public function a_realised_trade_shows_up_in_the_closed_trade_list(): void
    {
        $this->seedHistory();

        PortfolioTransaction::factory()
            ->for($this->portfolio)
            ->for($this->stock)
            ->sell(40, 700, Carbon::today()->subDays(10))
            ->create();

        $response = $this->getJson("/api/portfolios/{$this->portfolio->id}/performance")
            ->assertOk()
            ->assertJsonCount(1, 'data.closed_trades')
            ->assertJsonPath('data.closed_trades.0.symbol', 'NABIL');

        // 40 units bought at 400, sold at 700.
        $this->assertJsonNumber($response, 'data.closed_trades.0.gain', 12_000);
        $response->assertJsonPath('data.closed_trades.0.is_win', true);
    }

    // ------------------------------------------------------------------
    // /benchmark
    // ------------------------------------------------------------------

    #[Test]
    public function the_benchmark_returns_all_four_sections_by_default(): void
    {
        $this->seedHistory();
        $this->seedIndex(120);

        $this->getJson("/api/portfolios/{$this->portfolio->id}/benchmark")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'market' => [
                        'index_slug', 'index_name', 'as_of', 'since', 'index_available',
                        'index_coverage_percent', 'index_coverage_note',
                        'portfolio_value', 'portfolio_value_known',
                        'index_value', 'index_simulation_complete', 'index_uncovered_dates',
                        'value_vs_index', 'value_vs_index_percent', 'beat_index',
                    ],
                    'periods',
                    'risk',
                    'sectors',
                ],
            ])
            ->assertJsonPath('data.market.index_slug', 'nepse-index')
            ->assertJsonPath('data.market.index_available', true);
    }

    #[Test]
    public function the_sections_parameter_narrows_the_payload(): void
    {
        $this->seedHistory();
        $this->seedIndex(120);

        $response = $this->getJson("/api/portfolios/{$this->portfolio->id}/benchmark?sections=market")
            ->assertOk()
            ->assertJsonStructure(['data' => ['market']]);

        $this->assertSame(['market'], array_keys((array) $response->json('data')));

        // Unknown section names are intersected away rather than 422'd, but a
        // recognised one alongside them still comes back.
        $second = $this->getJson(
            "/api/portfolios/{$this->portfolio->id}/benchmark?sections=market,nonsense,sectors"
        )->assertOk();

        $this->assertSame(['market', 'sectors'], array_keys((array) $second->json('data')));
    }

    #[Test]
    public function a_complete_index_window_reports_full_coverage(): void
    {
        $this->seedHistory();
        $this->seedIndex(120);

        $response = $this->getJson("/api/portfolios/{$this->portfolio->id}/benchmark?sections=market")
            ->assertOk()
            ->assertJsonPath('data.market.index_simulation_complete', true)
            ->assertJsonPath('data.market.index_uncovered_dates', []);

        /*
         * Effectively complete. Not asserted as exactly 100 because sqlite
         * stores a `date` column as `Y-m-d H:i:s`, so the repository's
         * `trade_date <= '<Y-m-d>'` string comparison drops the final day's
         * row here in a way MySQL's DATE column would not. The series is
         * still "on or before" so the simulation is complete either way —
         * see the note on FeatureTestCase.
         */
        $this->assertGreaterThan(98.0, (float) $response->json('data.market.index_coverage_percent'));
        $this->assertNotNull($response->json('data.market.value_vs_index'));
        $this->assertIsBool($response->json('data.market.beat_index'));
    }

    #[Test]
    public function a_partial_index_window_is_flagged_incomplete_rather_than_quietly_wrong(): void
    {
        $this->seedHistory();
        // Index history that starts well after the first trade.
        $this->seedIndex(20);

        $response = $this->getJson("/api/portfolios/{$this->portfolio->id}/benchmark?sections=market")
            ->assertOk()
            ->assertJsonPath('data.market.index_available', true)
            ->assertJsonPath('data.market.index_simulation_complete', false);

        $this->assertNotEmpty($response->json('data.market.index_uncovered_dates'));
        $this->assertLessThan(100.0, (float) $response->json('data.market.index_coverage_percent'));
        $this->assertNotEmpty($response->json('data.market.index_coverage_note'));
    }

    #[Test]
    public function a_missing_index_reports_null_rather_than_a_fabricated_zero(): void
    {
        $this->seedHistory();
        // No market_indices rows at all.

        $this->getJson("/api/portfolios/{$this->portfolio->id}/benchmark?sections=market")
            ->assertOk()
            ->assertJsonPath('data.market.index_available', false)
            ->assertJsonPath('data.market.index_value', null)
            ->assertJsonPath('data.market.value_vs_index', null)
            ->assertJsonPath('data.market.value_vs_index_percent', null)
            ->assertJsonPath('data.market.beat_index', null)
            ->assertJsonPath('data.market.index_simulation_complete', false);
    }

    #[Test]
    public function a_portfolio_with_no_transactions_says_there_is_nothing_to_benchmark(): void
    {
        $this->seedIndex(120);

        $this->getJson("/api/portfolios/{$this->portfolio->id}/benchmark?sections=market")
            ->assertOk()
            ->assertJsonPath('data.market.since', null)
            ->assertJsonPath('data.market.value_vs_index', null)
            ->assertJsonPath(
                'data.market.index_coverage_note',
                'No transactions recorded yet; there is nothing to benchmark.',
            );
    }

    #[Test]
    public function it_can_be_compared_against_a_named_sub_index(): void
    {
        $this->seedHistory();
        $this->seedIndex(120);
        $this->seedIndex(120, 'banking-subindex', 'Banking SubIndex');

        $this->getJson("/api/portfolios/{$this->portfolio->id}/benchmark?sections=market&index=banking-subindex")
            ->assertOk()
            ->assertJsonPath('data.market.index_slug', 'banking-subindex')
            ->assertJsonPath('data.market.index_name', 'Banking SubIndex');
    }

    #[Test]
    public function the_sector_section_sets_each_sector_against_its_own_sub_index(): void
    {
        $this->seedHistory();
        $this->seedIndex(120);

        $this->getJson("/api/portfolios/{$this->portfolio->id}/benchmark?sections=sectors")
            ->assertOk()
            ->assertJsonPath('data.sectors.sectors.0.sector', 'Commercial Bank')
            ->assertJsonStructure(['data' => ['sectors' => ['as_of', 'sectors']]]);
    }

    #[Test]
    public function it_rejects_an_unknown_basis_or_sampling_interval(): void
    {
        $this->getJson("/api/portfolios/{$this->portfolio->id}/benchmark?basis=quarterly")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['basis']);

        $this->getJson("/api/portfolios/{$this->portfolio->id}/benchmark?interval=hourly")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['interval']);

        $this->getJson("/api/portfolios/{$this->portfolio->id}/benchmark?index=".str_repeat('x', 81))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['index']);
    }

    #[Test]
    public function a_trade_invalidates_the_cached_benchmark(): void
    {
        $this->seedHistory();
        $this->seedIndex(120);

        $before = (float) $this->getJson(
            "/api/portfolios/{$this->portfolio->id}/benchmark?sections=market"
        )->assertOk()->json('data.market.portfolio_value');

        $this->postJson("/api/portfolios/{$this->portfolio->id}/transactions", [
            'symbol' => 'NABIL',
            'type' => 'buy',
            'quantity' => 100,
            'price' => 500,
            'transacted_on' => Carbon::today()->subDays(5)->toDateString(),
        ])->assertCreated();

        $after = (float) $this->getJson(
            "/api/portfolios/{$this->portfolio->id}/benchmark?sections=market"
        )->assertOk()->json('data.market.portfolio_value');

        // A stale cache would have returned the pre-trade figure for the next
        // half hour, which reads as a bug rather than as caching.
        $this->assertGreaterThan($before, $after);
    }

    // ------------------------------------------------------------------
    // /attribution
    // ------------------------------------------------------------------

    #[Test]
    public function attribution_explains_an_empty_portfolio_instead_of_erroring(): void
    {
        $this->getJson("/api/portfolios/{$this->portfolio->id}/attribution")
            ->assertOk()
            ->assertJsonPath('data.window.from', null)
            ->assertJsonPath('data.window.first_transaction_on', null)
            ->assertJsonPath('data.total.return_percent', null)
            ->assertJsonPath('data.coverage.complete', true)
            ->assertJsonPath(
                'data.coverage.note',
                'This portfolio has no transactions, so there is no return to attribute.',
            );
    }

    #[Test]
    public function attribution_contributions_sum_to_the_total_return(): void
    {
        $this->seedHistory();

        $other = Stock::factory()->symbol('UPPER')->price(300)->create();
        StockPrice::factory()->for($other)->close(250)->on(Carbon::today()->subDays(60))->create();
        StockPrice::factory()->for($other)->close(300)->on(Carbon::today())->create();

        PortfolioTransaction::factory()
            ->for($this->portfolio)
            ->for($other)
            ->buy(200, 250, Carbon::today()->subDays(60))
            ->create();

        $response = $this->getJson("/api/portfolios/{$this->portfolio->id}/attribution")
            ->assertOk()
            ->assertJsonPath('data.reconciles', true)
            ->assertJsonStructure([
                'data' => [
                    'window' => ['from', 'to', 'days', 'first_transaction_on'],
                    'basis' => ['return', 'note'],
                    'total' => ['capital_base', 'profit', 'return_percent', 'holdings'],
                    'reconciles', 'reconciliation_error_percent',
                    'holdings', 'excluded',
                    'coverage' => [
                        'positions_measured', 'positions_excluded', 'excluded_symbols',
                        'capital_base_covered_percent', 'complete', 'note',
                    ],
                    'sectors', 'allocation_effect',
                ],
            ]);

        $holdings = (array) $response->json('data.holdings');

        $this->assertCount(2, $holdings);

        $summed = array_sum(array_map(
            static fn (array $row): float => (float) ($row['contribution_percent'] ?? 0.0),
            $holdings,
        ));

        // The tested invariant: a row that looks wrong is wrong, not a
        // rounding artefact.
        $this->assertEqualsWithDelta(
            (float) $response->json('data.total.return_percent'),
            $summed,
            0.05,
        );
    }

    #[Test]
    public function attribution_honours_an_explicit_window(): void
    {
        $this->seedHistory();

        $from = Carbon::today()->subDays(30)->toDateString();
        $to = Carbon::today()->subDays(5)->toDateString();

        $this->getJson("/api/portfolios/{$this->portfolio->id}/attribution?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonPath('data.window.from', $from)
            ->assertJsonPath('data.window.to', $to)
            ->assertJsonPath('data.window.days', 25);
    }

    #[Test]
    public function attribution_refuses_a_window_that_ends_after_the_start(): void
    {
        $this->seedHistory();

        $from = Carbon::today()->subDays(5)->toDateString();
        $to = Carbon::today()->subDays(30)->toDateString();

        $this->getJson("/api/portfolios/{$this->portfolio->id}/attribution?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonPath('data.total.return_percent', null)
            ->assertJsonPath('data.basis.note', 'The window start must be before the window end.');
    }

    #[Test]
    public function attribution_rejects_a_future_end_date_or_a_non_date(): void
    {
        $this->getJson(
            "/api/portfolios/{$this->portfolio->id}/attribution?to=".Carbon::tomorrow()->toDateString()
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);

        $this->getJson("/api/portfolios/{$this->portfolio->id}/attribution?from=not-a-date")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * One position bought 90 days ago at 400, priced daily up to 600 today, so
     * every replay boundary in the window can actually be marked.
     */
    private function seedHistory(): void
    {
        PortfolioTransaction::factory()
            ->for($this->portfolio)
            ->for($this->stock)
            ->buy(100, 400, Carbon::today()->subDays(90))
            ->create();

        // The position the ledger implies. Both exist in a real portfolio, and
        // the sector benchmark reads open holdings rather than transactions.
        PortfolioHolding::factory()
            ->for($this->portfolio)
            ->for($this->stock)
            ->position(100, 400)
            ->create();

        for ($daysAgo = 120; $daysAgo >= 0; $daysAgo--) {
            StockPrice::factory()
                ->for($this->stock)
                ->close(round(400 + (120 - $daysAgo) * (200 / 120), 2))
                ->on(Carbon::today()->subDays($daysAgo))
                ->create();
        }
    }

    /**
     * A daily index series covering the last $days days.
     */
    private function seedIndex(int $days, string $slug = 'nepse-index', string $name = 'NEPSE Index'): void
    {
        for ($daysAgo = $days; $daysAgo >= 0; $daysAgo--) {
            MarketIndex::factory()
                ->slug($slug)
                ->state(['name' => $name])
                ->on(Carbon::today()->subDays($daysAgo), round(2000 + ($days - $daysAgo) * 2, 4))
                ->create();
        }
    }
}
