<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\InvestorProfile;
use App\Enums\VerdictGrade;
use App\Services\Playbook\Backtest\Bar;
use App\Services\Playbook\Backtest\BarSeries;
use App\Services\Playbook\Backtest\ScheduledTradeCostCalculator;
use App\Services\Playbook\Backtest\TradeCostSchedule;
use App\Services\Playbook\Backtest\TradeOutcome;
use App\Services\Playbook\Backtest\TradePlan;
use App\Services\Playbook\Backtest\TradeSimulator;
use App\Services\Playbook\Backtest\TradeSubject;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The simulation core.
 *
 * Every assertion here is on a hand-built series of bars, so the suite stays
 * database-free (this project's phpunit.xml points at the real MySQL
 * connection — a test that needs rows is a test that cannot live here) and
 * every figure is checkable with a calculator.
 *
 * The cases that matter most are the pessimistic ones: stop before target on
 * the same bar, a gap honoured against the position, and — above all — an
 * unresolved plan returning null rather than a zero that would be averaged in
 * as a flat trade.
 */
final class PlaybookTradeSimulatorTest extends TestCase
{
    private function simulator(?TradeCostSchedule $schedule = null): TradeSimulator
    {
        return new TradeSimulator(new ScheduledTradeCostCalculator(
            $schedule ?? TradeCostSchedule::nepseEquityDefault(),
        ));
    }

    private function subject(): TradeSubject
    {
        return new TradeSubject('TEST', InvestorProfile::Swing, VerdictGrade::StrongBuy, 80.0);
    }

    private function plan(
        float $entryLow = 98.0,
        float $entryHigh = 102.0,
        float $stop = 95.0,
        float $target = 110.0,
        int $entryWindow = 5,
        int $maxHold = 10,
    ): TradePlan {
        return new TradePlan(
            signalDate: '2025-01-01',
            entryLow: $entryLow,
            entryHigh: $entryHigh,
            stopLoss: $stop,
            target: $target,
            entryWindowSessions: $entryWindow,
            maxHoldSessions: $maxHold,
        );
    }

    /**
     * @param  list<array{string, float, float, float, float}>  $rows  [date, open, high, low, close]
     */
    private function series(array $rows): BarSeries
    {
        return new BarSeries(array_map(
            static fn (array $row): Bar => new Bar(
                date: $row[0],
                close: $row[4],
                open: $row[1],
                high: $row[2],
                low: $row[3],
                volume: 1000.0,
            ),
            $rows,
        ));
    }

    #[Test]
    public function it_takes_the_target_and_prices_the_round_trip(): void
    {
        $trade = $this->simulator()->simulate(
            $this->plan(),
            $this->series([
                ['2025-01-02', 100.0, 101.0, 99.0, 100.0],
                ['2025-01-03', 101.0, 112.0, 100.0, 111.0],
            ]),
            $this->subject(),
            100_000.0,
        );

        $this->assertSame(TradeOutcome::Target, $trade->outcome);
        $this->assertSame('2025-01-02', $trade->entryDate);
        $this->assertSame(100.0, $trade->entryPrice);
        $this->assertSame('2025-01-03', $trade->exitDate);
        // The bar opened at 101 and traded through 110, so the target filled at
        // its own level rather than at the (better) high of the bar.
        $this->assertSame(110.0, $trade->exitPrice);
        $this->assertSame(2, $trade->holdSessions);
        $this->assertSame(1000, $trade->quantity);

        $this->assertSame(10.0, $trade->grossReturnPercent);
        // Rs 370 to buy 100,000 and Rs 404.50 to sell 110,000 under the default
        // schedule, against 100,370 of capital actually deployed.
        $this->assertSame(9225.5, $trade->netProfit);
        $this->assertSame(9.1915, $trade->netReturnPercent);
        $this->assertTrue($trade->isResolved());
    }

    #[Test]
    public function it_takes_the_stop_when_the_low_breaches_it(): void
    {
        $trade = $this->simulator()->simulate(
            $this->plan(),
            $this->series([
                ['2025-01-02', 100.0, 101.0, 99.0, 100.0],
                ['2025-01-03', 99.0, 100.0, 94.0, 95.0],
            ]),
            $this->subject(),
            100_000.0,
        );

        $this->assertSame(TradeOutcome::Stop, $trade->outcome);
        $this->assertSame(95.0, $trade->exitPrice);
        $this->assertSame(-5.0, $trade->grossReturnPercent);
        $this->assertNotNull($trade->netReturnPercent);
        $this->assertLessThan(-5.0, $trade->netReturnPercent);
    }

    #[Test]
    public function it_expires_a_trade_at_the_close_of_the_hold_window(): void
    {
        $trade = $this->simulator()->simulate(
            $this->plan(maxHold: 3),
            $this->series([
                ['2025-01-02', 100.0, 101.0, 99.0, 100.0],
                ['2025-01-03', 100.0, 103.0, 99.5, 102.0],
                ['2025-01-06', 102.0, 104.0, 101.0, 103.5],
                // Beyond the hold ceiling: the target it eventually reaches
                // must not be credited to a trade that had already been closed.
                ['2025-01-07', 104.0, 120.0, 103.0, 119.0],
            ]),
            $this->subject(),
            100_000.0,
        );

        $this->assertSame(TradeOutcome::Expired, $trade->outcome);
        $this->assertSame('2025-01-06', $trade->exitDate);
        $this->assertSame(103.5, $trade->exitPrice);
        $this->assertSame(3, $trade->holdSessions);
        $this->assertSame(3.5, $trade->grossReturnPercent);
    }

    #[Test]
    public function it_checks_the_stop_before_the_target_on_a_bar_that_touched_both(): void
    {
        $trade = $this->simulator()->simulate(
            $this->plan(),
            $this->series([
                ['2025-01-02', 100.0, 115.0, 94.0, 108.0],
            ]),
            $this->subject(),
            100_000.0,
        );

        // Daily bars cannot say which came first, and assuming the target did
        // would manufacture winners out of exactly the bars where the
        // assumption is least safe.
        $this->assertSame(TradeOutcome::Stop, $trade->outcome);
        $this->assertSame(95.0, $trade->exitPrice);
    }

    #[Test]
    public function it_honours_a_gap_through_the_stop_against_the_position(): void
    {
        $trade = $this->simulator()->simulate(
            $this->plan(),
            $this->series([
                ['2025-01-02', 100.0, 101.0, 99.0, 100.0],
                ['2025-01-03', 88.0, 90.0, 86.0, 87.0],
            ]),
            $this->subject(),
            100_000.0,
        );

        $this->assertSame(TradeOutcome::Stop, $trade->outcome);
        // You did not get 95: the market opened 8 below it.
        $this->assertSame(88.0, $trade->exitPrice);
    }

    #[Test]
    public function it_fills_a_resting_limit_at_the_open_or_at_the_limit_price(): void
    {
        $fromAbove = $this->simulator()->simulate(
            $this->plan(maxHold: 1),
            $this->series([['2025-01-02', 106.0, 107.0, 100.0, 101.0]]),
            $this->subject(),
            100_000.0,
        );

        // Opened above the limit and traded down to it: you paid the limit.
        $this->assertSame(102.0, $fromAbove->entryPrice);

        $atTheOpen = $this->simulator()->simulate(
            $this->plan(stop: 80.0, maxHold: 1),
            $this->series([['2025-01-02', 90.0, 99.0, 89.0, 98.5]]),
            $this->subject(),
            100_000.0,
        );

        // Opened below the limit: a resting buy fills at the open. Inventing an
        // in-band price the tape never printed would be a worse distortion than
        // crediting the gap, and the stop is still a long way below.
        $this->assertSame(90.0, $atTheOpen->entryPrice);
    }

    #[Test]
    public function a_plan_that_gaps_through_its_own_stop_is_void_rather_than_a_full_size_loss(): void
    {
        // Band 98-102, stop 95, and the market opens at 92. Nobody puts this
        // trade on. Filling it and stopping out on the same bar would
        // manufacture a maximum loss out of a trade that never happened — the
        // artefact that most inflates a stop rate.
        $trade = $this->simulator()->simulate(
            $this->plan(),
            $this->series([
                ['2025-01-02', 92.0, 96.0, 90.0, 94.0],
                ['2025-01-03', 100.0, 101.0, 99.0, 100.0],
            ]),
            $this->subject(),
            100_000.0,
        );

        $this->assertSame(TradeOutcome::NoFill, $trade->outcome);
        $this->assertNull($trade->entryPrice);
        $this->assertNull($trade->netReturnPercent);
    }

    #[Test]
    public function a_plan_the_market_never_traded_into_is_not_a_flat_trade(): void
    {
        $trade = $this->simulator()->simulate(
            $this->plan(entryWindow: 3),
            $this->series([
                ['2025-01-02', 120.0, 122.0, 118.0, 121.0],
                ['2025-01-03', 121.0, 123.0, 119.0, 122.0],
                ['2025-01-06', 122.0, 124.0, 120.0, 123.0],
                ['2025-01-07', 100.0, 101.0, 99.0, 100.0],
            ]),
            $this->subject(),
            100_000.0,
        );

        $this->assertSame(TradeOutcome::NoFill, $trade->outcome);
        $this->assertNull($trade->netReturnPercent);
        $this->assertNull($trade->grossReturnPercent);
        $this->assertNull($trade->entryPrice);
        $this->assertFalse($trade->isResolved());
    }

    #[Test]
    public function a_window_with_insufficient_data_is_unresolved_and_returns_null_not_zero(): void
    {
        // Filled, but the hold ceiling extends past the last stored bar.
        $openTrade = $this->simulator()->simulate(
            $this->plan(maxHold: 10),
            $this->series([
                ['2025-01-02', 100.0, 101.0, 99.0, 100.0],
                ['2025-01-03', 100.0, 102.0, 99.0, 101.0],
            ]),
            $this->subject(),
            100_000.0,
        );

        $this->assertSame(TradeOutcome::Unresolved, $openTrade->outcome);
        $this->assertNull($openTrade->netReturnPercent);
        $this->assertNull($openTrade->grossReturnPercent);
        $this->assertNull($openTrade->netProfit);
        $this->assertNull($openTrade->capitalDeployed());
        $this->assertNull($openTrade->totalCosts());
        $this->assertFalse($openTrade->isResolved());

        // Not even the entry window is covered by the data.
        $noData = $this->simulator()->simulate(
            $this->plan(entryWindow: 5),
            $this->series([['2025-01-02', 130.0, 131.0, 129.0, 130.0]]),
            $this->subject(),
            100_000.0,
        );

        $this->assertSame(TradeOutcome::Unresolved, $noData->outcome);
        $this->assertNull($noData->netReturnPercent);

        // No forward bars at all.
        $empty = $this->simulator()->simulate($this->plan(), BarSeries::empty(), $this->subject(), 100_000.0);

        $this->assertSame(TradeOutcome::Unresolved, $empty->outcome);
        $this->assertNull($empty->netReturnPercent);
    }

    #[Test]
    public function costs_turn_a_marginal_winner_into_a_loser(): void
    {
        // A momentum-sized target: +0.6% gross, against a round trip that
        // costs roughly 0.74% of notional.
        $plan = $this->plan(entryLow: 99.5, entryHigh: 100.5, stop: 97.0, target: 100.6);
        $bars = $this->series([
            ['2025-01-02', 100.0, 100.2, 99.8, 100.0],
            ['2025-01-03', 100.1, 100.7, 100.0, 100.6],
        ]);

        $free = $this->simulator(TradeCostSchedule::free())
            ->simulate($plan, $bars, $this->subject(), 100_000.0);
        $costed = $this->simulator()
            ->simulate($plan, $bars, $this->subject(), 100_000.0);

        $this->assertSame(TradeOutcome::Target, $free->outcome);
        $this->assertSame(TradeOutcome::Target, $costed->outcome);

        // Identical geometry, identical gross return.
        $this->assertSame($free->grossReturnPercent, $costed->grossReturnPercent);
        $this->assertGreaterThan(0.0, (float) $free->grossReturnPercent);

        // Uncosted it is a winner; costed it is not. This is the whole reason
        // the report carries both columns.
        $this->assertGreaterThan(0.0, (float) $free->netReturnPercent);
        $this->assertLessThan(0.0, (float) $costed->netReturnPercent);
        $this->assertSame(0.0, (float) $free->totalCosts());
        $this->assertGreaterThan(700.0, (float) $costed->totalCosts());
    }

    #[Test]
    public function it_records_the_excursions_the_trade_lived_through(): void
    {
        $trade = $this->simulator()->simulate(
            $this->plan(maxHold: 3),
            $this->series([
                ['2025-01-02', 100.0, 101.0, 99.0, 100.0],
                ['2025-01-03', 100.0, 108.0, 96.0, 97.0],
                ['2025-01-06', 97.0, 99.0, 96.5, 98.0],
            ]),
            $this->subject(),
            100_000.0,
        );

        $this->assertSame(TradeOutcome::Expired, $trade->outcome);
        $this->assertSame(8.0, $trade->maxFavourableExcursionPercent);
        $this->assertSame(-4.0, $trade->maxAdverseExcursionPercent);
    }

    #[Test]
    public function it_never_trades_the_signal_bar_itself(): void
    {
        // The simulator is handed BarSeries::after($signalDate) by the service,
        // so the signal bar is not reachable. Proving the cut is the caller's
        // contract is what BarSeries::after() is tested for; proving the
        // simulator honours the first available bar as the earliest fill is
        // this: an entry can never be dated on or before the signal.
        $trade = $this->simulator()->simulate(
            $this->plan(),
            $this->series([['2025-01-02', 100.0, 111.0, 99.0, 110.5]]),
            $this->subject(),
            100_000.0,
        );

        $this->assertNotNull($trade->entryDate);
        $this->assertGreaterThan('2025-01-01', (string) $trade->entryDate);
    }
}
