<?php

declare(strict_types=1);

namespace App\Services\Scraper\Backfill;

/**
 * Pure gap arithmetic over plain date arrays.
 *
 * Which dates are real gaps is answered entirely from what the market
 * calendar has *observed* (see MarketCalendarClassifier) - never from a
 * weekday rule, since NEPSE's trading week is not a constant. This class only
 * does the set arithmetic: which confirmed trading days a symbol is missing,
 * and the span of dates dense enough evidence exists to trust an absence.
 */
final class BackfillGapAnalyzer
{
    /**
     * Confirmed trading days this symbol has no bar for - a real gap, worth
     * fetching. A date that is merely "unknown" (never confirmed either way)
     * is deliberately never reported as a gap here: asserting a gap on an
     * unresolved date would be exactly the same mistake as asserting a
     * holiday on one.
     *
     * @param  list<string>  $confirmedTradingDays  Y-m-d dates classified `trading_day`.
     * @param  list<string>  $existingDates  Y-m-d dates already stored for this symbol.
     * @return list<string>
     */
    public function findMissingSessions(array $confirmedTradingDays, array $existingDates): array
    {
        $existing = array_flip($existingDates);

        return array_values(array_filter(
            $confirmedTradingDays,
            static fn (string $date): bool => ! isset($existing[$date]),
        ));
    }

    /**
     * The date span dense evidence actually exists around: the earliest and
     * latest date whose coverage reaches a "healthy" fraction of the
     * reference population being measured (the liquid basket, typically).
     *
     * Only dates *inside* this span may be treated as "we actually checked
     * and found nothing" - a date can qualify on its own even while sitting
     * right next to a real holiday, since the holiday itself will show
     * near-zero coverage but the well-covered dates either side of it still
     * bound a span that contains it. That is exactly how a holiday embedded
     * in otherwise-dense history gets recognised without every single date
     * individually needing to clear the bar.
     *
     * @param  array<string, int>  $coverageByDate  Y-m-d date => distinct symbols with a bar that day.
     * @param  int  $typicalBreadth  Size of the population being measured (e.g. the basket size).
     * @param  float  $wellCoveredFraction  Fraction of $typicalBreadth a date's coverage must reach.
     * @return array{0: string, 1: string}|null
     */
    public function wellCoveredSpan(array $coverageByDate, int $typicalBreadth, float $wellCoveredFraction): ?array
    {
        if ($typicalBreadth <= 0) {
            return null;
        }

        $minimumCoverage = $wellCoveredFraction * $typicalBreadth;

        $qualifyingDates = array_keys(array_filter(
            $coverageByDate,
            static fn (int $count): bool => $count >= $minimumCoverage,
        ));

        if ($qualifyingDates === []) {
            return null;
        }

        sort($qualifyingDates);

        return [$qualifyingDates[0], $qualifyingDates[array_key_last($qualifyingDates)]];
    }

    /**
     * Whether a date falls inside a span returned by wellCoveredSpan().
     *
     * @param  array{0: string, 1: string}|null  $span
     */
    public function withinSpan(string $date, ?array $span): bool
    {
        if ($span === null) {
            return false;
        }

        return $date >= $span[0] && $date <= $span[1];
    }
}
