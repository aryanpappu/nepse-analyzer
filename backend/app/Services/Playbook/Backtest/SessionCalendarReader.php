<?php

declare(strict_types=1);

namespace App\Services\Playbook\Backtest;

use App\Models\MarketCalendarDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Which dates in a window were sessions — observed, never predicted.
 *
 * The rule this follows is the one `MarketCalendarClassifier` already
 * enforces, applied read-only: **a manual override wins, then a bar that was
 * actually observed makes the date a trading day.** There is deliberately no
 * weekday filter anywhere in this class. NEPSE moved from Sunday-Thursday to
 * Monday-Friday and may move again, and Nepali closures follow the BS festival
 * calendar; a backtest that sampled `* * 0-4` would silently drop or invent
 * sessions across a regime change, and its date arithmetic — hold windows,
 * entry windows — is counted in sessions.
 *
 * `market_calendar_days` is consulted first but is not required: it is
 * currently empty on this database, and an empty classification table is not
 * evidence that no market existed. The observed bars in `stock_prices` are the
 * same evidence the classifier itself uses to write a `trading_day` row, so
 * they stand in for it, and `sessionSource` says which was used.
 */
final class SessionCalendarReader
{
    /**
     * @return array{dates: list<string>, source: string, manual_trading_days: int, manual_holidays: int, suspected_gaps: list<string>}
     */
    public function sessionsBetween(Carbon $from, Carbon $to): array
    {
        $fromDate = $from->copy()->startOfDay()->toDateString();
        $toDate = $to->copy()->startOfDay()->toDateString();

        $observed = DB::table('stock_prices')
            ->distinct()
            ->whereBetween('trade_date', [$fromDate, $toDate])
            ->orderBy('trade_date')
            ->pluck('trade_date')
            ->map(static fn (mixed $date): string => Carbon::parse((string) $date)->toDateString())
            ->all();

        $sessions = array_fill_keys($observed, true);

        $calendar = MarketCalendarDay::query()
            ->whereBetween('trade_date', [$fromDate, $toDate])
            ->get(['trade_date', 'classification', 'classified_by']);

        $manualTradingDays = 0;
        $manualHolidays = 0;

        foreach ($calendar as $day) {
            $date = $day->trade_date->toDateString();
            $manual = $day->classified_by === MarketCalendarDay::CLASSIFIED_BY_MANUAL;

            if ($day->classification === MarketCalendarDay::HOLIDAY && $manual) {
                // A human has said this date was shut. That outranks a stray bar.
                unset($sessions[$date]);
                $manualHolidays++;

                continue;
            }

            if ($day->classification === MarketCalendarDay::TRADING_DAY) {
                $sessions[$date] = true;

                if ($manual) {
                    $manualTradingDays++;
                }
            }
        }

        ksort($sessions);

        $dates = array_keys($sessions);

        return [
            'dates' => $dates,
            'source' => $calendar->isEmpty()
                ? 'observed bars (market_calendar_days is empty for this window)'
                : sprintf('observed bars + %d market_calendar_days rows', $calendar->count()),
            'manual_trading_days' => $manualTradingDays,
            'manual_holidays' => $manualHolidays,
            'suspected_gaps' => $this->suspectedGaps($dates, $calendar->pluck('classification', 'trade_date')->all()),
        ];
    }

    /**
     * Dates with no bar at all, sitting between two consecutive observed
     * sessions, and not classified as a holiday.
     *
     * This is the `unknown` case the calendar classifier exists for, surfaced
     * rather than spanned. A dead crawler and a shut market both produce zero
     * rows; a single blank date wedged between two trading days is far more
     * likely to be the first. Reporting it lets a reader discount a window
     * instead of quietly measuring hold lengths in sessions that never existed
     * — and, critically, it is reported rather than being assumed a holiday,
     * which would permanently write that date out of every future backfill.
     *
     * @param  list<string>  $sessions  Ascending.
     * @param  array<string, string>  $classifications
     * @return list<string>
     */
    private function suspectedGaps(array $sessions, array $classifications): array
    {
        $gaps = [];
        $known = array_fill_keys($sessions, true);

        for ($i = 1, $n = count($sessions); $i < $n; $i++) {
            $previous = Carbon::parse($sessions[$i - 1]);
            $current = Carbon::parse($sessions[$i]);

            // Only a single blank day between two sessions. A longer break is
            // an ordinary weekend or festival closure and says nothing.
            if ((int) $previous->diffInDays($current) !== 2) {
                continue;
            }

            $missing = $previous->copy()->addDay()->toDateString();

            if (isset($known[$missing])) {
                continue;
            }

            $classified = null;

            foreach ($classifications as $date => $classification) {
                if (Carbon::parse((string) $date)->toDateString() === $missing) {
                    $classified = $classification;

                    break;
                }
            }

            if ($classified === MarketCalendarDay::HOLIDAY) {
                continue;
            }

            $gaps[] = $missing;
        }

        return $gaps;
    }
}
