<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Portfolio\IndexSeries;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pure lookup-table maths, no database and no app boot — {@see IndexSeries}
 * depends on nothing but Carbon, exactly like InterestAccrualCalculatorTest.
 */
final class IndexSeriesTest extends TestCase
{
    private function point(string $date, float $value): array
    {
        return ['date' => Carbon::parse($date), 'value' => $value];
    }

    #[Test]
    public function an_empty_series_answers_nothing_but_never_zero(): void
    {
        $series = new IndexSeries([]);

        self::assertTrue($series->isEmpty());
        self::assertNull($series->earliestDate());
        self::assertNull($series->latestDate());
        self::assertNull($series->valueOnOrBefore(Carbon::parse('2025-01-01')));
        self::assertSame(0.0, $series->coverage(Carbon::parse('2025-01-01'), Carbon::parse('2025-02-01')));
    }

    #[Test]
    public function a_lookup_before_the_series_starts_is_null_not_the_first_value(): void
    {
        $series = new IndexSeries([
            $this->point('2025-03-01', 2000.0),
            $this->point('2025-03-05', 2050.0),
        ]);

        self::assertNull($series->valueOnOrBefore(Carbon::parse('2025-02-01')));
    }

    #[Test]
    public function a_lookup_on_a_gap_returns_the_last_known_level(): void
    {
        // Index prints on the 1st and the 10th; a NEPSE holiday or scraper
        // gap sits in between — the 6th must still resolve to the 1st's
        // level, not null and not the 10th's.
        $series = new IndexSeries([
            $this->point('2025-01-01', 2000.0),
            $this->point('2025-01-10', 2100.0),
        ]);

        self::assertSame(2000.0, $series->valueOnOrBefore(Carbon::parse('2025-01-06')));
        self::assertSame(2000.0, $series->valueOnOrBefore(Carbon::parse('2025-01-01')));
        self::assertSame(2100.0, $series->valueOnOrBefore(Carbon::parse('2025-01-10')));
        self::assertSame(2100.0, $series->valueOnOrBefore(Carbon::parse('2025-06-01')));
    }

    #[Test]
    public function coverage_reports_the_fraction_of_the_window_the_series_actually_spans(): void
    {
        $series = new IndexSeries([
            $this->point('2025-02-01', 2000.0),
            $this->point('2025-03-01', 2100.0),
        ]);

        // Window is Jan 1 -> Mar 1 (60 days inclusive); data only starts Feb 1
        // (29 days of that window are covered: Feb 1 -> Mar 1 inclusive).
        $coverage = $series->coverage(Carbon::parse('2025-01-01'), Carbon::parse('2025-03-01'));

        self::assertEqualsWithDelta(29 / 60, $coverage, 0.01);
    }

    #[Test]
    public function coverage_is_full_when_the_series_spans_the_whole_window(): void
    {
        $series = new IndexSeries([
            $this->point('2025-01-01', 2000.0),
            $this->point('2025-06-01', 2200.0),
        ]);

        self::assertSame(1.0, $series->coverage(Carbon::parse('2025-02-01'), Carbon::parse('2025-03-01')));
    }
}
