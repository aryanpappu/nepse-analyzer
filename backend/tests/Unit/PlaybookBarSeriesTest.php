<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Playbook\Backtest\Bar;
use App\Services\Playbook\Backtest\BarSeries;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The cut that makes look-ahead structurally impossible.
 *
 * `upTo()` and `after()` are the only two ways the replay reaches a bar. The
 * scoring half gets the first, the simulator the second, and the boundary bar —
 * the session the verdict was scored on — belongs to neither the future nor the
 * tradeable set. Getting these two off by one session is the single bug that
 * would silently make the whole backtest worthless, so the boundary is asserted
 * from both sides.
 */
final class PlaybookBarSeriesTest extends TestCase
{
    private function series(): BarSeries
    {
        // Deliberately out of order on construction: the class sorts.
        return new BarSeries([
            new Bar(date: '2025-01-08', close: 108.0, high: 109.0, low: 107.0),
            new Bar(date: '2025-01-02', close: 102.0, high: 103.0, low: 101.0),
            new Bar(date: '2025-01-06', close: 106.0, high: 107.0, low: 105.0),
            new Bar(date: '2025-01-03', close: 103.0, high: 104.0, low: 102.0),
        ]);
    }

    #[Test]
    public function it_sorts_and_deduplicates_on_construction(): void
    {
        $series = new BarSeries([
            new Bar(date: '2025-01-03', close: 1.0),
            new Bar(date: '2025-01-02', close: 2.0),
            new Bar(date: '2025-01-03', close: 3.0),
        ]);

        $this->assertSame(2, $series->count());
        $this->assertSame('2025-01-02', $series->firstDate());
        $this->assertSame('2025-01-03', $series->lastDate());
        $this->assertSame(3.0, $series->barOn('2025-01-03')?->close);
    }

    #[Test]
    public function up_to_includes_the_boundary_and_nothing_after_it(): void
    {
        $past = $this->series()->upTo('2025-01-06');

        $this->assertSame(3, $past->count());
        $this->assertSame('2025-01-06', $past->lastDate());
        $this->assertNotNull($past->barOn('2025-01-06'));
        $this->assertNull($past->barOn('2025-01-08'));
    }

    #[Test]
    public function after_excludes_the_boundary_because_the_signal_bar_is_already_spent(): void
    {
        $forward = $this->series()->after('2025-01-06');

        $this->assertSame(1, $forward->count());
        $this->assertSame('2025-01-08', $forward->firstDate());
        // The bar the verdict was scored on must not be tradeable: the close it
        // was scored on had already printed.
        $this->assertNull($forward->barOn('2025-01-06'));
    }

    #[Test]
    public function the_two_halves_partition_the_series_exactly(): void
    {
        $series = $this->series();

        foreach (['2025-01-01', '2025-01-03', '2025-01-06', '2025-01-09'] as $cut) {
            $this->assertSame(
                $series->count(),
                $series->upTo($cut)->count() + $series->after($cut)->count(),
                sprintf('upTo + after must lose no bar and duplicate none at %s.', $cut),
            );
        }
    }

    #[Test]
    public function up_to_a_date_before_the_series_is_empty_not_the_whole_series(): void
    {
        $past = $this->series()->upTo('2024-12-31');

        $this->assertTrue($past->isEmpty());
        $this->assertSame(0, $past->count());
        $this->assertNull($past->firstDate());
    }

    #[Test]
    public function range_aggregates_respect_both_bounds(): void
    {
        $series = $this->series();

        $this->assertSame(104.0, $series->highBetween('2025-01-02', '2025-01-03'));
        $this->assertSame(101.0, $series->lowBetween('2025-01-02', '2025-01-03'));
        $this->assertSame(109.0, $series->highBetween('2025-01-01', '2025-01-31'));
    }

    #[Test]
    public function a_window_with_no_bars_aggregates_to_null_not_zero(): void
    {
        $series = $this->series();

        // A high of 0 would read as a scrip that traded at nothing, and would
        // poison every ratio the engine builds from it.
        $this->assertNull($series->highBetween('2024-01-01', '2024-12-31'));
        $this->assertNull($series->lowBetween('2024-01-01', '2024-12-31'));
    }

    #[Test]
    public function a_partial_bar_falls_back_to_its_close(): void
    {
        $series = new BarSeries([new Bar(date: '2025-01-02', close: 55.0)]);

        $this->assertSame(55.0, $series->highBetween('2025-01-01', '2025-01-31'));
        $this->assertSame(55.0, $series->lowBetween('2025-01-01', '2025-01-31'));
        $this->assertSame(55.0, $series->barOn('2025-01-02')?->openOrClose());
    }

    #[Test]
    public function a_gap_in_the_calendar_is_a_missing_bar_not_an_interpolated_one(): void
    {
        // 2026-09-08 has zero price rows on this database — a real crawler gap.
        // The series must report the absence rather than spanning it, because
        // the observed-calendar rule turns on exactly that distinction.
        $series = new BarSeries([
            new Bar(date: '2026-09-07', close: 100.0),
            new Bar(date: '2026-09-09', close: 104.0),
        ]);

        $this->assertNull($series->barOn('2026-09-08'));
        $this->assertSame(2, $series->count());
        $this->assertSame(1, $series->after('2026-09-07')->count());
    }
}
