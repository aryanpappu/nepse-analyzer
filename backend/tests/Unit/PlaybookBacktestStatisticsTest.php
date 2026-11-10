<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\InvestorProfile;
use App\Enums\VerdictGrade;
use App\Services\Playbook\Backtest\BacktestStatistics;
use App\Services\Playbook\Backtest\SimulatedTrade;
use App\Services\Playbook\Backtest\TradeOutcome;
use App\Services\Playbook\Backtest\TradeSubject;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The aggregation, and the `n/a, never 0` rule it has to enforce.
 *
 * A grade nothing resolved has no hit rate. Printing 0% next to a real one
 * would be read as a finding — "avoid never wins" — when the truth is "avoid
 * was never measured". Every performance field therefore stays null while the
 * plan counts stay real, so a reader can see both that the slice exists and
 * that nothing is known about it.
 */
final class PlaybookBacktestStatisticsTest extends TestCase
{
    private function statistics(): BacktestStatistics
    {
        return new BacktestStatistics;
    }

    private function subject(float $score = 80.0): TradeSubject
    {
        return new TradeSubject('TEST', InvestorProfile::Swing, VerdictGrade::StrongBuy, $score);
    }

    private function resolved(float $netPercent, TradeOutcome $outcome, int $hold = 5): SimulatedTrade
    {
        // 100,000 of turnover with 370 of buy-side cost, scaled so the net
        // return percent comes out as asked.
        $deployed = 100_370.0;
        $netProfit = $netPercent / 100 * $deployed;

        return new SimulatedTrade(
            subject: $this->subject(),
            signalDate: '2025-01-01',
            outcome: $outcome,
            entryDate: '2025-01-02',
            entryPrice: 100.0,
            exitDate: '2025-01-09',
            exitPrice: 100.0 + $netPercent,
            holdSessions: $hold,
            quantity: 1_000,
            buyTurnover: 100_000.0,
            sellTurnover: 100_000.0 + $netProfit + 740.0,
            buyCosts: 370.0,
            sellCosts: 370.0,
            grossProfit: $netProfit + 740.0,
            netProfit: $netProfit,
            grossReturnPercent: round(($netProfit + 740.0) / 100_000.0 * 100, 4),
            netReturnPercent: $netPercent,
            maxFavourableExcursionPercent: 1.0,
            maxAdverseExcursionPercent: -1.0,
        );
    }

    #[Test]
    public function an_empty_slice_is_null_not_a_zeroed_summary(): void
    {
        $this->assertNull($this->statistics()->summarize([]));
    }

    #[Test]
    public function a_slice_whose_plans_all_went_unresolved_reports_null_performance_and_real_counts(): void
    {
        $stats = $this->statistics()->summarize([
            SimulatedTrade::unfilled($this->subject(), '2025-01-01', TradeOutcome::Unresolved),
            SimulatedTrade::unfilled($this->subject(), '2025-01-02', TradeOutcome::Unresolved),
            SimulatedTrade::unfilled($this->subject(), '2025-01-03', TradeOutcome::NoFill),
        ]);

        $this->assertNotNull($stats);
        $this->assertSame(3, $stats->planned());
        $this->assertSame(0, $stats->resolved);
        $this->assertSame(2, $stats->unresolved);
        $this->assertSame(1, $stats->noFill);
        $this->assertSame(0.0, $stats->resolutionPercent());

        // Everything that would be a claim about performance is n/a.
        $this->assertNull($stats->hitRatePercent);
        $this->assertNull($stats->targetHitRatePercent);
        $this->assertNull($stats->averageGrossReturnPercent);
        $this->assertNull($stats->averageNetReturnPercent);
        $this->assertNull($stats->medianNetReturnPercent);
        $this->assertNull($stats->stdDevNetReturnPercent);
        $this->assertNull($stats->averageHoldSessions);
        $this->assertNull($stats->totalNetProfit);
        $this->assertNull($stats->costDragPercent);
    }

    #[Test]
    public function it_separates_the_hit_rate_from_the_target_hit_rate(): void
    {
        $stats = $this->statistics()->summarize([
            $this->resolved(9.0, TradeOutcome::Target),
            $this->resolved(-5.4, TradeOutcome::Stop),
            // Expired above water: the plan did not work as written, but the
            // money came out ahead. Only one of the two rates should count it.
            $this->resolved(2.0, TradeOutcome::Expired),
            $this->resolved(-1.0, TradeOutcome::Expired),
        ]);

        $this->assertNotNull($stats);
        $this->assertSame(4, $stats->resolved);
        $this->assertSame(50.0, $stats->hitRatePercent);          // 9.0 and 2.0
        $this->assertSame(25.0, $stats->targetHitRatePercent);    // one target
        $this->assertSame(25.0, $stats->stopRatePercent);
        $this->assertSame(50.0, $stats->expiredRatePercent);
    }

    #[Test]
    public function it_averages_gross_and_net_separately(): void
    {
        $stats = $this->statistics()->summarize([
            $this->resolved(10.0, TradeOutcome::Target),
            $this->resolved(-6.0, TradeOutcome::Stop),
        ]);

        $this->assertNotNull($stats);
        $this->assertSame(2.0, $stats->averageNetReturnPercent);
        // Gross is always the better number by roughly the round-trip cost;
        // reporting only one of them is how a backtest flatters itself.
        $this->assertGreaterThan((float) $stats->averageNetReturnPercent, (float) $stats->averageGrossReturnPercent);
        $this->assertSame(1_480.0, $stats->totalCosts);
        $this->assertNotNull($stats->costDragPercent);
    }

    #[Test]
    public function it_reports_median_spread_and_extremes(): void
    {
        $stats = $this->statistics()->summarize([
            $this->resolved(-10.0, TradeOutcome::Stop),
            $this->resolved(1.0, TradeOutcome::Expired),
            $this->resolved(3.0, TradeOutcome::Expired),
            $this->resolved(30.0, TradeOutcome::Target),
        ]);

        $this->assertNotNull($stats);
        $this->assertSame(2.0, $stats->medianNetReturnPercent);
        $this->assertSame(30.0, $stats->bestNetReturnPercent);
        $this->assertSame(-10.0, $stats->worstNetReturnPercent);
        $this->assertNotNull($stats->stdDevNetReturnPercent);
        // The median being far below the mean is the shape that matters: a few
        // targets carrying a lot of small losers.
        $this->assertLessThan((float) $stats->averageNetReturnPercent, (float) $stats->medianNetReturnPercent);
    }

    #[Test]
    public function dispersion_is_undefined_rather_than_zero_on_a_single_trade(): void
    {
        $stats = $this->statistics()->summarize([$this->resolved(4.0, TradeOutcome::Target)]);

        $this->assertNotNull($stats);
        $this->assertSame(1, $stats->resolved);
        $this->assertNull($stats->stdDevNetReturnPercent);
    }

    #[Test]
    public function it_buckets_the_return_distribution(): void
    {
        $stats = $this->statistics()->summarize([
            $this->resolved(-25.0, TradeOutcome::Stop),
            $this->resolved(-6.0, TradeOutcome::Stop),
            $this->resolved(1.0, TradeOutcome::Expired),
            $this->resolved(25.0, TradeOutcome::Target),
        ]);

        $this->assertNotNull($stats);

        $distribution = $stats->distribution;

        $this->assertSame(1, $distribution['< -20%']);
        $this->assertSame(1, $distribution['-10 to -5%']);
        $this->assertSame(1, $distribution['0 to 2%']);
        $this->assertSame(1, $distribution['>= 20%']);
        $this->assertSame(4, array_sum($distribution));
    }

    #[Test]
    public function the_average_score_covers_every_plan_not_only_the_resolved_ones(): void
    {
        // Score is a property of the verdict, which exists whether or not the
        // plan ever filled, so it is the one figure that stays meaningful on an
        // unresolved slice.
        $stats = $this->statistics()->summarize([
            SimulatedTrade::unfilled(new TradeSubject('A', InvestorProfile::Swing, VerdictGrade::Avoid, 20.0), '2025-01-01', TradeOutcome::NoFill),
            SimulatedTrade::unfilled(new TradeSubject('B', InvestorProfile::Swing, VerdictGrade::Avoid, 30.0), '2025-01-01', TradeOutcome::NoFill),
        ]);

        $this->assertNotNull($stats);
        $this->assertSame(25.0, $stats->averageScore);
        $this->assertNull($stats->averageNetReturnPercent);
    }
}
