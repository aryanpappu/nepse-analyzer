<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Stock;
use App\Models\StockFundamental;
use App\Models\TechnicalIndicator;
use App\Services\Playbook\Backtest\Bar;
use App\Services\Playbook\Backtest\BarSeries;
use App\Services\Playbook\Backtest\HistoricalPlaybookInputFactory;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Look-ahead prevention.
 *
 * This is the test that decides whether the backtest is worth anything. The
 * live `PlaybookInputFactory` takes an `$asOf` argument but honours it in only
 * one of six places — price, indicator, fundamental, session count and volume
 * all come from "latest", unbounded — so a replay built on it would score 2023
 * with 2026 information and report a spectacular edge that does not exist.
 *
 * Each case below closes one of those channels, and the fixture is built so
 * that a leak would be *loud*: the future bar is a 10x spike, so any aggregate
 * that saw it would be off by an order of magnitude rather than a rounding.
 */
final class HistoricalPlaybookInputFactoryTest extends TestCase
{
    private function factory(): HistoricalPlaybookInputFactory
    {
        return new HistoricalPlaybookInputFactory;
    }

    private function stock(): Stock
    {
        return new Stock(['symbol' => 'TEST', 'name' => 'Test Scrip', 'instrument_type' => 'equity']);
    }

    /**
     * A quiet run around 100, then a 10x spike two sessions after the date the
     * replay is asked about.
     */
    private function seriesWithFutureSpike(): BarSeries
    {
        return new BarSeries([
            new Bar(date: '2025-01-02', close: 100.0, open: 100.0, high: 101.0, low: 99.0, volume: 1_000.0),
            new Bar(date: '2025-01-03', close: 102.0, open: 100.0, high: 103.0, low: 98.0, volume: 2_000.0),
            new Bar(date: '2025-01-06', close: 104.0, open: 102.0, high: 105.0, low: 96.0, volume: 3_000.0),
            // Everything below is the future, as at 2025-01-06.
            new Bar(date: '2025-01-07', close: 1_000.0, open: 900.0, high: 1_050.0, low: 20.0, volume: 999_000.0),
            new Bar(date: '2025-01-08', close: 1_100.0, open: 1_000.0, high: 1_200.0, low: 10.0, volume: 888_000.0),
        ]);
    }

    #[Test]
    public function it_cannot_see_a_future_bar_even_when_handed_one(): void
    {
        // The full series, future included, is passed in on purpose: the
        // factory truncates it itself rather than trusting the caller.
        $inputs = $this->factory()->build($this->stock(), $this->seriesWithFutureSpike(), '2025-01-06');

        $this->assertNotNull($inputs);

        // Price is that session's close, not the last close in the table.
        $this->assertSame(104.0, $inputs->price);

        // The 20-session range stops at the signal date: 105 high, 96 low.
        // Had the 2025-01-07 bar leaked in, the high would be 1,050 and the
        // low 20 — and rangePosition20() would read near the bottom of a
        // huge range instead of near the top of a small one.
        $this->assertSame(105.0, $inputs->high20);
        $this->assertSame(96.0, $inputs->low20);
        $this->assertSame(105.0, $inputs->high52w);
        $this->assertSame(96.0, $inputs->low52w);

        // Session count is bars up to the signal, not every bar stored.
        $this->assertSame(3, $inputs->sessions);

        // Volume is that session's, not the spike's.
        $this->assertSame(3_000.0, $inputs->volume);
    }

    #[Test]
    public function the_range_position_it_reports_is_the_one_the_engine_would_have_seen(): void
    {
        $inputs = $this->factory()->build($this->stock(), $this->seriesWithFutureSpike(), '2025-01-06');

        $this->assertNotNull($inputs);

        // (104 - 96) / (105 - 96) = 0.888..., a scrip closing near the top of
        // its range. With the future bar leaked it would be 0.081 — the engine
        // would have graded the opposite setup.
        $this->assertEqualsWithDelta(0.8889, (float) $inputs->rangePosition20(), 0.0001);
    }

    #[Test]
    public function a_scrip_that_did_not_trade_on_the_signal_date_gets_no_verdict(): void
    {
        // No bar on 2025-01-05: nothing was tradeable, so there is nothing to
        // score. Marking it at the previous close would invent a session.
        $this->assertNull($this->factory()->build($this->stock(), $this->seriesWithFutureSpike(), '2025-01-05'));
    }

    #[Test]
    public function a_zero_or_missing_close_produces_no_inputs_rather_than_a_zero_price(): void
    {
        $series = new BarSeries([new Bar(date: '2025-01-02', close: 0.0)]);

        $this->assertNull($this->factory()->build($this->stock(), $series, '2025-01-02'));
    }

    #[Test]
    public function an_indicator_dated_after_the_signal_throws_rather_than_being_used(): void
    {
        $indicator = new TechnicalIndicator(['trade_date' => '2025-02-01', 'rsi_14' => 65.0]);

        // Failing loudly is the point: silently dropping a future row would
        // hide the bug that produced it, and silently using it would be the
        // bias this whole class exists to prevent.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Look-ahead: indicator dated 2025-02-01');

        $this->factory()->build($this->stock(), $this->seriesWithFutureSpike(), '2025-01-06', $indicator);
    }

    #[Test]
    public function an_indicator_dated_on_or_before_the_signal_is_accepted(): void
    {
        $indicator = new TechnicalIndicator(['trade_date' => '2025-01-06', 'rsi_14' => 65.0]);

        $inputs = $this->factory()->build($this->stock(), $this->seriesWithFutureSpike(), '2025-01-06', $indicator);

        $this->assertNotNull($inputs);
        $this->assertSame($indicator, $inputs->indicator);
    }

    #[Test]
    public function an_undated_fundamental_is_refused_because_it_cannot_be_placed_in_time(): void
    {
        // `stock_fundamentals.reported_on` has historically been NULL across
        // the whole table, and the stored pe_ratio was struck at the price on
        // the day it was scraped. Accepting such a row into a 2025 replay
        // hands the engine a 2026 multiple.
        $fundamental = new StockFundamental(['fiscal_period' => '2081/2082 Q3', 'eps' => 40.0, 'pe_ratio' => 12.0]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('undated fundamental');

        $this->factory()->build($this->stock(), $this->seriesWithFutureSpike(), '2025-01-06', null, $fundamental);
    }

    #[Test]
    public function a_fundamental_reported_after_the_signal_throws(): void
    {
        $fundamental = new StockFundamental(['reported_on' => '2025-04-15', 'eps' => 40.0]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Look-ahead: fundamental reported 2025-04-15');

        $this->factory()->build($this->stock(), $this->seriesWithFutureSpike(), '2025-01-06', null, $fundamental);
    }

    #[Test]
    public function a_fundamental_reported_before_the_signal_is_accepted(): void
    {
        $fundamental = new StockFundamental(['reported_on' => '2024-11-30', 'eps' => 40.0]);

        $inputs = $this->factory()->build($this->stock(), $this->seriesWithFutureSpike(), '2025-01-06', null, $fundamental);

        $this->assertNotNull($inputs);
        $this->assertSame($fundamental, $inputs->fundamental);
    }

    #[Test]
    public function high_and_low_fall_back_to_the_close_on_partial_bars(): void
    {
        // Older scraped rows carry only a close. The live factory coalesces
        // high/low to close; the replay must do the same or it would read a
        // narrower range than the engine did.
        $series = new BarSeries([
            new Bar(date: '2025-01-02', close: 90.0),
            new Bar(date: '2025-01-03', close: 110.0),
        ]);

        $inputs = $this->factory()->build($this->stock(), $series, '2025-01-03');

        $this->assertNotNull($inputs);
        $this->assertSame(110.0, $inputs->high20);
        $this->assertSame(90.0, $inputs->low20);
    }
}
