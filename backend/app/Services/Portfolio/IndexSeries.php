<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use Illuminate\Support\Carbon;

/**
 * A market index's level history as a pure, dated lookup table.
 *
 * Holds no database connection and no knowledge of `App\Models\MarketIndex` —
 * it is built from plain (date, value) pairs, the same device
 * `PerformanceService` uses when it hands `rateOfReturn()` a plain array of
 * dated flows instead of a query. That is what lets every benchmark
 * calculation in this namespace be unit-tested without touching MySQL.
 *
 * Every lookup is "on or before", the same convention
 * `PortfolioTimeSeries::closesOn()` uses for stock closes: an index that did
 * not print a level on the exact date asked (a NEPSE holiday, a scraper gap)
 * is still valued at its last known level. A date before the series starts
 * returns null rather than 0 — a boundary the data cannot reach is unknown,
 * not a benchmark that was worth nothing.
 */
final class IndexSeries
{
    /** @var list<string> Y-m-d, ascending, deduplicated. */
    private readonly array $dates;

    /** @var array<string, float> Y-m-d => index level. */
    private readonly array $values;

    /**
     * @param  list<array{date: Carbon, value: float}>  $points  Order does not matter.
     */
    public function __construct(array $points)
    {
        $values = [];

        foreach ($points as $point) {
            $values[$point['date']->toDateString()] = $point['value'];
        }

        ksort($values);

        $this->values = $values;
        $this->dates = array_keys($values);
    }

    public function isEmpty(): bool
    {
        return $this->dates === [];
    }

    public function earliestDate(): ?Carbon
    {
        return $this->dates === [] ? null : Carbon::parse($this->dates[0])->startOfDay();
    }

    public function latestDate(): ?Carbon
    {
        return $this->dates === [] ? null : Carbon::parse($this->dates[count($this->dates) - 1])->startOfDay();
    }

    /**
     * The most recent level on or before $date, or null when $date is before
     * the series starts (or the series is empty altogether).
     */
    public function valueOnOrBefore(Carbon $date): ?float
    {
        if ($this->dates === []) {
            return null;
        }

        $target = $date->toDateString();

        // Binary search for the rightmost date <= target. The series is
        // typically a full trading history — hundreds to a few thousand rows
        // — looked up once per cash flow, so this stays fast without needing
        // a real index.
        $low = 0;
        $high = count($this->dates) - 1;
        $found = null;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);

            if ($this->dates[$mid] <= $target) {
                $found = $this->dates[$mid];
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return $found === null ? null : $this->values[$found];
    }

    /**
     * Fraction of [$from, $to] (inclusive, counted in days) the series
     * actually has a level for. This is what lets a caller say "benchmark
     * unavailable before 2024" instead of silently implying a complete
     * comparison over a window the index data never reached.
     */
    public function coverage(Carbon $from, Carbon $to): float
    {
        $totalDays = max(1, (int) $from->diffInDays($to) + 1);
        $earliest = $this->earliestDate();
        $latest = $this->latestDate();

        if ($earliest === null || $latest === null || $earliest->greaterThan($to) || $latest->lessThan($from)) {
            return 0.0;
        }

        $coveredFrom = $earliest->greaterThan($from) ? $earliest : $from;
        $coveredTo = $latest->lessThan($to) ? $latest : $to;
        $coveredDays = max(0, (int) $coveredFrom->diffInDays($coveredTo) + 1);

        return round($coveredDays / $totalDays, 4);
    }
}
