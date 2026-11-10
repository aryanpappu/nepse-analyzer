<?php

declare(strict_types=1);

namespace App\Services\Playbook\Backtest;

use App\Models\Stock;
use App\Models\StockFundamental;
use App\Models\TechnicalIndicator;
use App\Services\Playbook\PlaybookInputFactory;
use App\Services\Playbook\PlaybookInputs;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Builds the engine's inputs **as at a past date**, and refuses to build them
 * any other way.
 *
 * This class exists because the live {@see PlaybookInputFactory}
 * cannot be reused for a replay. It takes an `$asOf` argument, but only its
 * range aggregates honour it: `price` comes from the denormalised
 * `stocks.last_price`, `indicator` from `latestIndicator` (max `trade_date`,
 * unbounded), `fundamental` from `latestFundamental`, the session count from an
 * ungrouped `count(*)` over the whole of `stock_prices`, and the volume from
 * the globally latest bar. Handing it a 2023 date returns 2026 momentum. Every
 * one of those five is a look-ahead channel, and this factory closes all five:
 *
 * 1. **Price** is the close of the bar *on* `$asOf`. No bar on that date means
 *    no verdict — the scrip did not trade, so there was nothing to act on.
 * 2. **Range windows** are computed from a series truncated to `<= $asOf`
 *    (`BarSeries::upTo()`), over the same calendar spans the live factory uses
 *    (30 days for the 20-session read, 365 for the 52-week read) so the replay
 *    feeds the engine the number it would have had, not a better one.
 * 3. **Session count** is the bars on or before `$asOf`, not every bar stored.
 * 4. **Indicator** must be dated on or before `$asOf`; a future row throws
 *    rather than being silently dropped, because silently dropping it would
 *    hide the bug that produced it.
 * 5. **Fundamentals** must be dated, and dated on or before `$asOf`. An
 *    undated row is rejected: `stock_fundamentals.reported_on` has historically
 *    been NULL on every row, and a P/E computed at scrape time from a 2026
 *    price is the single largest look-ahead channel in this dataset. The
 *    caller decides the policy (see `BacktestSettings::$fundamentalsMode`);
 *    this class enforces it.
 *
 * Pure apart from Carbon date arithmetic on dates it is given — no clock, no
 * query, no config.
 */
final class HistoricalPlaybookInputFactory
{
    /** Calendar days behind $asOf for the "20-session" range, matching the live factory. */
    private const RANGE_20_DAYS = 30;

    /** Calendar days behind $asOf for the 52-week range, matching the live factory. */
    private const RANGE_52W_DAYS = 365;

    /**
     * @param  BarSeries  $history  May contain future bars; it is truncated here regardless.
     * @param  string  $asOf  Y-m-d. Must be a session the scrip actually traded on.
     */
    public function build(
        Stock $stock,
        BarSeries $history,
        string $asOf,
        ?TechnicalIndicator $indicator = null,
        ?StockFundamental $fundamental = null,
    ): ?PlaybookInputs {
        $this->assertNotFuture($indicator, $fundamental, $asOf);

        $past = $history->upTo($asOf);
        $bar = $past->barOn($asOf);

        if ($bar === null || $bar->close <= 0.0) {
            return null;
        }

        $anchor = Carbon::parse($asOf)->startOfDay();
        $from20 = $anchor->copy()->subDays(self::RANGE_20_DAYS)->toDateString();
        $from52 = $anchor->copy()->subDays(self::RANGE_52W_DAYS)->toDateString();

        return new PlaybookInputs(
            stock: $stock,
            price: $bar->close,
            indicator: $indicator,
            fundamental: $fundamental,
            sessions: $past->count(),
            high20: $past->highBetween($from20, $asOf),
            low20: $past->lowBetween($from20, $asOf),
            high52w: $past->highBetween($from52, $asOf),
            low52w: $past->lowBetween($from52, $asOf),
            volume: $bar->volume,
        );
    }

    private function assertNotFuture(?TechnicalIndicator $indicator, ?StockFundamental $fundamental, string $asOf): void
    {
        $indicatorDate = $indicator?->trade_date;

        if ($indicatorDate !== null && $indicatorDate->toDateString() > $asOf) {
            throw new LogicException(sprintf(
                'Look-ahead: indicator dated %s handed to a replay as at %s.',
                $indicatorDate->toDateString(),
                $asOf,
            ));
        }

        if ($fundamental === null) {
            return;
        }

        $reportedOn = $fundamental->reported_on;

        if ($reportedOn === null) {
            throw new LogicException(
                'Look-ahead: an undated fundamental (reported_on is null) cannot be placed in time. '
                .'Use the "none" fundamentals mode, or populate reported_on.'
            );
        }

        if ($reportedOn->toDateString() > $asOf) {
            throw new LogicException(sprintf(
                'Look-ahead: fundamental reported %s handed to a replay as at %s.',
                $reportedOn->toDateString(),
                $asOf,
            ));
        }
    }
}
