<?php

declare(strict_types=1);

namespace App\Services\Scraper\Backfill;

use Illuminate\Support\Carbon;

/**
 * Enumerates calendar dates between two dates.
 *
 * NEPSE's trading week is not a constant - it was Sunday-Thursday and has
 * since moved to Monday-Friday, and public holidays follow the Bikram Sambat
 * calendar, which no formula reproduces - so nothing here decides whether a
 * date is a *session*. That question belongs to MarketCalendarClassifier,
 * answered from what has actually been observed (see HistoryBackfillService).
 * `allDatesBetween()` is the one method the rest of the backfill uses: every
 * calendar day in a range, unfiltered by weekday.
 *
 * `sessionsBetween()` / `sessionCountBetween()` (a fixed weekday filter) are
 * kept only because they are cheap, already tested, and harmless to have
 * around as a possible future prioritisation hint ("check these dates
 * first") - they are deliberately not used to gate any classification or gap
 * decision any more, which is exactly the mistake effective-dating them would
 * still make: a single answer for a trading week that has already changed
 * once and may change again.
 *
 * A pure function over dates - no config(), no DB - so the backfill logic
 * built on top of it is unit-testable without booting the framework.
 */
final class TradingSessionCalendar
{
    /**
     * @param  list<int>  $tradingWeekdays  ISO-8601 weekday numbers (1=Mon..7=Sun).
     *                                      Only consulted by the legacy
     *                                      sessionsBetween()/sessionCountBetween()
     *                                      helpers - see the class docblock.
     */
    public function __construct(
        private readonly array $tradingWeekdays = [],
    ) {}

    /**
     * Every calendar date between $from and $to, inclusive, unfiltered.
     *
     * @return list<string> Y-m-d dates.
     */
    public function allDatesBetween(Carbon $from, Carbon $to): array
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        $dates = [];
        $cursor = $start->copy();

        while ($cursor->lessThanOrEqualTo($end)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $dates;
    }

    /**
     * @return list<string> Y-m-d dates, inclusive of both ends, that fall on a
     *                      configured trading weekday. See the class
     *                      docblock - not used to gate any decision.
     */
    public function sessionsBetween(Carbon $from, Carbon $to): array
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        $sessions = [];
        $cursor = $start->copy();

        while ($cursor->lessThanOrEqualTo($end)) {
            if (in_array((int) $cursor->dayOfWeekIso, $this->tradingWeekdays, true)) {
                $sessions[] = $cursor->toDateString();
            }

            $cursor->addDay();
        }

        return $sessions;
    }

    /**
     * How many trading sessions fall between the two dates on the configured
     * weekday set. See the class docblock - not used to gate any decision.
     */
    public function sessionCountBetween(Carbon $from, Carbon $to): int
    {
        return count($this->sessionsBetween($from, $to));
    }
}
