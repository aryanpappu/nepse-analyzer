<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Portfolio\IndexReturnCalculator;
use App\Services\Portfolio\IndexSeries;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pure index-benchmark maths, no database and no app boot — {@see
 * IndexReturnCalculator} takes plain arrays of dated values and Carbon
 * instances only, exactly like PerformanceServiceTest's flow literals and
 * InterestAccrualCalculatorTest.
 *
 * Cash-flow sign convention under test throughout matches
 * PerformanceService::cashFlow(): negative = money invested (a buy),
 * positive = money returned (a sell or dividend).
 */
final class IndexReturnCalculatorTest extends TestCase
{
    private function calculator(): IndexReturnCalculator
    {
        return new IndexReturnCalculator;
    }

    private function series(array $pairs): IndexSeries
    {
        return new IndexSeries(array_map(
            static fn (array $pair): array => ['date' => Carbon::parse($pair[0]), 'value' => $pair[1]],
            $pairs,
        ));
    }

    // ------------------------------------------------------------------
    // periodReturn()
    // ------------------------------------------------------------------

    #[Test]
    public function period_return_is_the_percent_change_between_the_two_boundary_levels(): void
    {
        $series = $this->series([['2025-01-01', 2000.0], ['2025-12-31', 2200.0]]);

        $result = $this->calculator()->periodReturn($series, Carbon::parse('2025-01-01'), Carbon::parse('2025-12-31'));

        self::assertSame(10.0, $result['return_percent']);
        self::assertTrue($result['complete']);
    }

    #[Test]
    public function period_return_is_null_over_a_window_with_no_index_data_at_all(): void
    {
        $empty = new IndexSeries([]);

        $result = $this->calculator()->periodReturn($empty, Carbon::parse('2025-01-01'), Carbon::parse('2025-12-31'));

        self::assertNull($result['return_percent']);
        self::assertFalse($result['complete']);
        self::assertNull($result['start_value']);
        self::assertNull($result['end_value']);
    }

    // ------------------------------------------------------------------
    // simulateInvestment() — the money-weighted "bought the index instead"
    // ------------------------------------------------------------------

    #[Test]
    public function a_flat_index_shows_the_portfolios_full_outperformance(): void
    {
        // Rs 100,000 invested in a flat index is worth exactly Rs 100,000 a
        // year later — every rupee the real portfolio made beyond that is
        // entirely its own outperformance, not index tailwind.
        $series = $this->series([['2025-01-01', 2000.0], ['2025-12-31', 2000.0]]);

        $result = $this->calculator()->simulateInvestment(
            $series,
            [['date' => Carbon::parse('2025-01-01'), 'amount' => -100000.0]],
            Carbon::parse('2025-12-31'),
        );

        self::assertTrue($result->known());
        self::assertSame(100000.0, $result->value);
        self::assertTrue($result->complete);
        self::assertSame([], $result->uncoveredDates);
    }

    #[Test]
    public function a_single_cash_flow_buys_units_at_that_dates_level_and_marks_them_at_the_end(): void
    {
        // Rs 100,000 at a level of 2000 buys 50 units; the index doubles to
        // 4000, so those 50 units are worth Rs 200,000 at the end.
        $series = $this->series([['2025-01-01', 2000.0], ['2025-12-31', 4000.0]]);

        $result = $this->calculator()->simulateInvestment(
            $series,
            [['date' => Carbon::parse('2025-01-01'), 'amount' => -100000.0]],
            Carbon::parse('2025-12-31'),
        );

        self::assertSame(50.0, $result->unitsHeld);
        self::assertSame(200000.0, $result->value);
    }

    #[Test]
    public function money_withdrawn_stops_earning_from_the_date_it_left(): void
    {
        // Buy 50 units at 2000. Halfway through, at a level of 3000, redeem
        // Rs 60,000 worth (20 units), leaving 30 units. The index then rises
        // to 4000, so the remaining 30 units are worth Rs 120,000 — the
        // withdrawn Rs 60,000 does not participate in the rest of the move.
        $series = $this->series([
            ['2025-01-01', 2000.0],
            ['2025-06-01', 3000.0],
            ['2025-12-31', 4000.0],
        ]);

        $result = $this->calculator()->simulateInvestment(
            $series,
            [
                ['date' => Carbon::parse('2025-01-01'), 'amount' => -100000.0],
                ['date' => Carbon::parse('2025-06-01'), 'amount' => 60000.0],
            ],
            Carbon::parse('2025-12-31'),
        );

        self::assertEqualsWithDelta(30.0, $result->unitsHeld, 0.0001);
        self::assertSame(120000.0, $result->value);
    }

    #[Test]
    public function a_gap_mid_window_still_prices_flows_at_the_last_known_level(): void
    {
        // The index has no print on the flow's exact date (a holiday/gap);
        // the flow must still be priced at the last known level rather than
        // rejected as uncovered.
        $series = $this->series([
            ['2025-01-01', 2000.0],
            ['2025-01-20', 2100.0],
        ]);

        $result = $this->calculator()->simulateInvestment(
            $series,
            [['date' => Carbon::parse('2025-01-10'), 'amount' => -100000.0]],
            Carbon::parse('2025-01-20'),
        );

        // Priced at 2000 (the last known level on or before Jan 10):
        // 50 units, worth 105,000 once the index reaches 2100.
        self::assertTrue($result->complete);
        self::assertEqualsWithDelta(50.0, $result->unitsHeld, 0.0001);
        self::assertSame(105000.0, $result->value);
    }

    #[Test]
    public function a_flow_before_any_index_data_is_excluded_and_surfaced_not_priced_at_zero(): void
    {
        $series = $this->series([['2025-06-01', 2000.0]]);

        $result = $this->calculator()->simulateInvestment(
            $series,
            [
                ['date' => Carbon::parse('2025-01-01'), 'amount' => -50000.0], // before the series starts
                ['date' => Carbon::parse('2025-06-01'), 'amount' => -50000.0],
            ],
            Carbon::parse('2025-06-01'),
        );

        self::assertFalse($result->complete);
        self::assertSame(['2025-01-01'], $result->uncoveredDates);
        // Only the priced flow bought units: 50,000 / 2000 = 25.
        self::assertSame(25.0, $result->unitsHeld);
        self::assertNotNull($result->value);
    }

    #[Test]
    public function a_window_with_no_index_data_at_all_reports_unknown_not_zero(): void
    {
        $empty = new IndexSeries([]);

        $result = $this->calculator()->simulateInvestment(
            $empty,
            [['date' => Carbon::parse('2025-01-01'), 'amount' => -100000.0]],
            Carbon::parse('2025-12-31'),
        );

        self::assertFalse($result->known());
        self::assertNull($result->value);
        self::assertFalse($result->complete);
        self::assertSame(['2025-01-01'], $result->uncoveredDates);
    }

    // ------------------------------------------------------------------
    // cashFlowAdjustedReturn()
    // ------------------------------------------------------------------

    #[Test]
    public function cash_flow_adjusted_return_matches_the_profit_over_capital_base_convention(): void
    {
        // Opened at 100, a further 50 was added mid-period, closed at 180.
        // Profit = 180 - 100 - 50 = 30; capital base = 100 + 50 = 150.
        $return = $this->calculator()->cashFlowAdjustedReturn(100.0, 180.0, 50.0);

        self::assertEqualsWithDelta(20.0, $return, 0.0001);
    }

    #[Test]
    public function cash_flow_adjusted_return_is_null_with_no_capital_at_risk(): void
    {
        self::assertNull($this->calculator()->cashFlowAdjustedReturn(0.0, 0.0, 0.0));
    }

    // ------------------------------------------------------------------
    // regression()
    // ------------------------------------------------------------------

    #[Test]
    public function beta_of_a_portfolio_that_moves_exactly_with_the_index_is_one(): void
    {
        $pairs = [
            ['index' => 1.0, 'portfolio' => 1.0],
            ['index' => -2.0, 'portfolio' => -2.0],
            ['index' => 3.0, 'portfolio' => 3.0],
            ['index' => 0.5, 'portfolio' => 0.5],
        ];

        $result = $this->calculator()->regression($pairs, 'weekly', 52);

        self::assertSame(1.0, $result->beta);
        self::assertSame(1.0, $result->correlation);
        self::assertSame(0.0, $result->alphaPercentPerPeriod);
        self::assertSame(4, $result->sampleSize);
    }

    #[Test]
    public function a_portfolio_that_consistently_beats_the_index_by_a_fixed_amount_shows_that_as_alpha(): void
    {
        $pairs = [
            ['index' => 1.0, 'portfolio' => 2.0],
            ['index' => -2.0, 'portfolio' => -1.0],
            ['index' => 3.0, 'portfolio' => 4.0],
        ];

        $result = $this->calculator()->regression($pairs, 'weekly', 52);

        self::assertEqualsWithDelta(1.0, $result->beta, 0.0001);
        self::assertEqualsWithDelta(1.0, $result->alphaPercentPerPeriod, 0.0001);
        self::assertEqualsWithDelta(52.0, $result->alphaPercentAnnualized, 0.01);
    }

    #[Test]
    public function beta_is_undefined_rather_than_a_fabricated_number_when_the_index_never_moves(): void
    {
        $pairs = [
            ['index' => 0.0, 'portfolio' => 1.0],
            ['index' => 0.0, 'portfolio' => -2.0],
            ['index' => 0.0, 'portfolio' => 3.0],
        ];

        $result = $this->calculator()->regression($pairs, 'weekly', 52);

        self::assertNull($result->beta);
        self::assertNull($result->correlation);
        self::assertNull($result->alphaPercentPerPeriod);
        self::assertSame(3, $result->sampleSize);
    }

    #[Test]
    public function regression_needs_at_least_two_observations(): void
    {
        $result = $this->calculator()->regression([['index' => 1.0, 'portfolio' => 1.0]], 'weekly', 52);

        self::assertNull($result->beta);
        self::assertSame(1, $result->sampleSize);
    }

    #[Test]
    public function regression_over_no_observations_at_all_reports_zero_sample_not_a_number(): void
    {
        $result = $this->calculator()->regression([], 'monthly', 12);

        self::assertSame(0, $result->sampleSize);
        self::assertNull($result->beta);
        self::assertNull($result->correlation);
        self::assertNull($result->alphaPercentAnnualized);
    }
}
