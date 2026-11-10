<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\InvestorProfile;
use App\Enums\VerdictGrade;
use App\Services\Playbook\Backtest\IndexTradeComparator;
use App\Services\Playbook\Backtest\SimulatedTrade;
use App\Services\Playbook\Backtest\TradeOutcome;
use App\Services\Playbook\Backtest\TradeSubject;
use App\Services\Portfolio\IndexReturnCalculator;
use App\Services\Portfolio\IndexSeries;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The comparison that decides whether the engine earns its place.
 *
 * "`strong_buy` beat `avoid`" is a weak claim — both could be losing money in a
 * rising market. So each trade is measured against the index over *its own*
 * entry-to-exit window, which means the strategy gets no credit for merely
 * being invested during a rally.
 *
 * All index arithmetic is delegated to `IndexReturnCalculator` and
 * `IndexSeries`, which are the portfolio namespace's own tested primitives —
 * this class is asserted for the plumbing and for the coverage behaviour, not
 * for re-derived index maths.
 */
final class PlaybookIndexComparisonTest extends TestCase
{
    private function comparator(): IndexTradeComparator
    {
        return new IndexTradeComparator(new IndexReturnCalculator);
    }

    /**
     * A NEPSE-like series that starts partway through the window — the exact
     * shape of this database, whose index history begins in 2023 while the
     * price history reaches further back.
     */
    private function series(): IndexSeries
    {
        return new IndexSeries([
            ['date' => Carbon::parse('2025-02-01'), 'value' => 2_000.0],
            ['date' => Carbon::parse('2025-02-10'), 'value' => 2_100.0],
            ['date' => Carbon::parse('2025-03-01'), 'value' => 2_200.0],
            ['date' => Carbon::parse('2025-04-01'), 'value' => 2_400.0],
        ]);
    }

    private function trade(
        string $entry,
        string $exit,
        float $netPercent,
        TradeOutcome $outcome = TradeOutcome::Target,
        VerdictGrade $grade = VerdictGrade::StrongBuy,
    ): SimulatedTrade {
        $deployed = 100_370.0;
        $netProfit = $netPercent / 100 * $deployed;

        return new SimulatedTrade(
            subject: new TradeSubject('TEST', InvestorProfile::Swing, $grade, 80.0),
            signalDate: $entry,
            outcome: $outcome,
            entryDate: $entry,
            entryPrice: 100.0,
            exitDate: $exit,
            exitPrice: 100.0 + $netPercent,
            holdSessions: 5,
            quantity: 1_000,
            buyTurnover: 100_000.0,
            sellTurnover: 100_000.0 + $netProfit + 740.0,
            buyCosts: 370.0,
            sellCosts: 370.0,
            grossProfit: $netProfit + 740.0,
            netProfit: $netProfit,
            grossReturnPercent: round(($netProfit + 740.0) / 100_000.0 * 100, 4),
            netReturnPercent: $netPercent,
        );
    }

    #[Test]
    public function it_measures_a_trade_against_the_index_over_that_trades_own_window(): void
    {
        // 2,000 to 2,200 is +10% over exactly the window the trade was held.
        $matched = $this->comparator()->matchedReturn(
            $this->series(),
            $this->trade('2025-02-01', '2025-03-01', 12.0),
        );

        $this->assertSame(10.0, $matched);
    }

    #[Test]
    public function excess_return_is_reported_gross_and_net(): void
    {
        $comparison = $this->comparator()->compare(
            $this->series(),
            [$this->trade('2025-02-01', '2025-03-01', 12.0)],
            Carbon::parse('2025-02-01'),
            Carbon::parse('2025-04-01'),
            'nepse-index',
            'NEPSE Index',
        );

        $this->assertSame(1, $comparison->matchedTrades);
        $this->assertSame(10.0, $comparison->averageIndexReturnPercent);
        $this->assertSame(12.0, $comparison->averageStrategyNetReturnPercent);
        $this->assertSame(2.0, $comparison->averageExcessNetPercent);

        // Gross beats net by the round trip, so the gross excess is larger —
        // and a strategy can beat the index gross and lose to it net.
        $this->assertGreaterThan(
            (float) $comparison->averageExcessNetPercent,
            (float) $comparison->averageExcessGrossPercent,
        );
        $this->assertTrue($comparison->beatsIndexNet());
        $this->assertSame(100.0, $comparison->winVersusIndexPercent);
    }

    #[Test]
    public function costs_can_flip_the_verdict_against_the_index(): void
    {
        // A trade that beat the index by 0.2% gross loses to it once the round
        // trip is paid. Reporting only the gross column would say "beat".
        $comparison = $this->comparator()->compare(
            $this->series(),
            [$this->trade('2025-02-01', '2025-03-01', 9.5)],
            Carbon::parse('2025-02-01'),
            Carbon::parse('2025-04-01'),
            'nepse-index',
            'NEPSE Index',
        );

        $this->assertGreaterThan(0.0, (float) $comparison->averageExcessGrossPercent);
        $this->assertLessThan(0.0, (float) $comparison->averageExcessNetPercent);
        $this->assertFalse($comparison->beatsIndexNet());
    }

    #[Test]
    public function a_trade_the_index_cannot_price_is_excluded_and_counted_not_benchmarked_at_zero(): void
    {
        $comparison = $this->comparator()->compare(
            $this->series(),
            [
                // Entered before the index series begins: unpriceable.
                $this->trade('2024-06-01', '2024-07-01', 15.0),
                $this->trade('2025-02-01', '2025-03-01', 12.0),
            ],
            Carbon::parse('2024-06-01'),
            Carbon::parse('2025-04-01'),
            'nepse-index',
            'NEPSE Index',
        );

        $this->assertSame(1, $comparison->matchedTrades);
        $this->assertSame(1, $comparison->unmatchedTrades);
        $this->assertContains('2024-06-01', $comparison->uncoveredDates);
        // The matched average must not have been dragged by a 0% benchmark.
        $this->assertSame(10.0, $comparison->averageIndexReturnPercent);
        // And the coverage figure must say the window was only partly measured.
        $this->assertNotNull($comparison->coveragePercent);
        $this->assertLessThan(100.0, (float) $comparison->coveragePercent);
    }

    #[Test]
    public function unresolved_trades_are_never_benchmarked(): void
    {
        $comparison = $this->comparator()->compare(
            $this->series(),
            [
                SimulatedTrade::unfilled(
                    new TradeSubject('TEST', InvestorProfile::Swing, VerdictGrade::Buy, 70.0),
                    '2025-02-01',
                    TradeOutcome::Unresolved,
                ),
                SimulatedTrade::unfilled(
                    new TradeSubject('TEST', InvestorProfile::Swing, VerdictGrade::Buy, 70.0),
                    '2025-02-01',
                    TradeOutcome::NoFill,
                ),
            ],
            Carbon::parse('2025-02-01'),
            Carbon::parse('2025-04-01'),
            'nepse-index',
            'NEPSE Index',
        );

        $this->assertSame(0, $comparison->matchedTrades);
        $this->assertNull($comparison->averageExcessNetPercent);
        $this->assertNull($comparison->beatsIndexNet());
        // The whole-window index return is still known and still reported.
        $this->assertSame(20.0, $comparison->windowReturnPercent);
    }

    #[Test]
    public function no_index_history_reports_unavailable_rather_than_a_zero_percent_market(): void
    {
        $comparison = $this->comparator()->compare(
            new IndexSeries([]),
            [$this->trade('2025-02-01', '2025-03-01', 12.0)],
            Carbon::parse('2025-02-01'),
            Carbon::parse('2025-04-01'),
            'nepse-index',
            null,
        );

        $this->assertNull($comparison->windowReturnPercent);
        $this->assertNull($comparison->coveragePercent);
        $this->assertNull($comparison->averageExcessNetPercent);
        $this->assertNull($comparison->beatsIndexNet());
        $this->assertFalse($comparison->windowComplete);
    }

    #[Test]
    public function it_reports_the_rupee_alternative_of_buying_the_index_instead(): void
    {
        // One trade, Rs 100,370 deployed on 2025-02-01 at an index level of
        // 2,000; the window ends at 2,400, so the same money in the index would
        // be worth 100,370 * 1.2 = 120,444.
        $comparison = $this->comparator()->compare(
            $this->series(),
            [$this->trade('2025-02-01', '2025-03-01', 12.0)],
            Carbon::parse('2025-02-01'),
            Carbon::parse('2025-04-01'),
            'nepse-index',
            'NEPSE Index',
        );

        $this->assertEqualsWithDelta(120_444.0, (float) $comparison->indexBuyAndHoldValue, 0.5);
        // The strategy made 12% and stopped; the index kept going. Beating the
        // market on matched windows and still trailing buy-and-hold is a real
        // outcome for a short-hold strategy, and both figures are reported.
        $this->assertEqualsWithDelta(112_414.4, (float) $comparison->strategyTerminalValue, 0.5);
        $this->assertLessThan(0.0, (float) $comparison->terminalDifference);
    }
}
