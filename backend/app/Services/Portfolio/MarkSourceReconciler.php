<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use Illuminate\Support\Carbon;

/**
 * Decides which of the app's mark sources prices a scrip at a given date.
 *
 * The bug this exists to close: `PortfolioValuationService` priced a holding
 * from `stocks.last_price` while `PortfolioTimeSeries` refused to, because the
 * two read different tables and neither knew what the other did. On NICADF —
 * `last_price` 10.05, no row in `stock_prices`, no row in `fund_navs` — one
 * counted the position and the other silently dropped it, so the same
 * portfolio had two market values and the difference was invisible in both.
 *
 * Pure and DB-free, in the same spirit as `MarketCalendarClassifier`: one
 * strict precedence, decided in one place, so the two callers cannot answer
 * differently.
 *
 * **Precedence.**
 *
 * 1. A **series mark** — `stock_prices.close`, or `fund_navs` for a fund with
 *    no quote — dated on or before the requested date. Preferred always: it is
 *    dated, reproducible, and the only kind of mark that can price a
 *    *historical* boundary.
 * 2. Otherwise the **spot** `stocks.last_price`, and only if `last_traded_on`
 *    is known and falls on or before the requested date. This is the fix and
 *    the limit of it: the price existed by then, so it may mark that date and
 *    later, but it can never be back-dated to a boundary before it.
 * 3. Otherwise **unpriced**. Null, named, and excluded by the caller — never
 *    valued at cost, which would report a 0% gain as though it were measured.
 *
 * An **undated** `last_price` — `last_traded_on` null — is refused outright at
 * step 2. A price with no date cannot mark any date, including today: today's
 * value would then be indistinguishable from a figure typed in years ago. No
 * row currently has that shape and this is what keeps it that way.
 *
 * Staleness never suppresses a price. Discarding the only mark a scrip has
 * would put the position straight back into the excluded list, which is the
 * outcome this is trying to avoid; so `isStale` is a *reporting* flag and the
 * caller surfaces it.
 */
final class MarkSourceReconciler
{
    /**
     * Days after which a spot mark is flagged stale in a report. A reporting
     * threshold only — it changes no value, ever.
     */
    public const DEFAULT_STALE_AFTER_DAYS = 30;

    /**
     * @param  Carbon  $asAt  The date a price is wanted for.
     * @param  float|null  $seriesPrice  Close, or fund NAV/market price.
     * @param  Carbon|null  $seriesDate  The date that series row carries.
     * @param  string|null  $seriesSource  HoldingMark::SOURCE_CLOSE or SOURCE_NAV.
     * @param  float|null  $spotPrice  `stocks.last_price`.
     * @param  Carbon|null  $spotDate  `stocks.last_traded_on`.
     */
    public function reconcile(
        int $stockId,
        Carbon $asAt,
        ?float $seriesPrice,
        ?Carbon $seriesDate,
        ?string $seriesSource,
        ?float $spotPrice,
        ?Carbon $spotDate,
        int $staleAfterDays = self::DEFAULT_STALE_AFTER_DAYS,
    ): HoldingMark {
        $asAt = $asAt->copy()->startOfDay();

        if ($seriesPrice !== null && $seriesPrice > 0.0 && $seriesDate !== null) {
            $date = $seriesDate->copy()->startOfDay();

            if ($date->lessThanOrEqualTo($asAt)) {
                $stale = $this->days($date, $asAt);

                return new HoldingMark(
                    stockId: $stockId,
                    price: round($seriesPrice, 4),
                    source: $seriesSource ?? HoldingMark::SOURCE_CLOSE,
                    asOf: $date,
                    staleDays: $stale,
                    seriesBacked: true,
                    isStale: $stale > $staleAfterDays,
                    reason: sprintf(
                        'Marked from the price history at %s, the last dated record on or before %s.',
                        $date->toDateString(),
                        $asAt->toDateString(),
                    ),
                );
            }
        }

        if ($spotPrice !== null && $spotPrice > 0.0) {
            if ($spotDate === null) {
                return $this->unpriced(
                    $stockId,
                    'stocks.last_price is set but stocks.last_traded_on is not, so the price has no '
                        .'date and cannot mark one. Excluded rather than applied to an arbitrary date.',
                );
            }

            $date = $spotDate->copy()->startOfDay();

            if ($date->greaterThan($asAt)) {
                return $this->unpriced(
                    $stockId,
                    sprintf(
                        'The only price available is dated %s, after %s. A later price cannot be '
                            .'back-dated to mark an earlier boundary.',
                        $date->toDateString(),
                        $asAt->toDateString(),
                    ),
                );
            }

            $stale = $this->days($date, $asAt);

            return new HoldingMark(
                stockId: $stockId,
                price: round($spotPrice, 4),
                source: HoldingMark::SOURCE_SPOT,
                asOf: $date,
                staleDays: $stale,
                seriesBacked: false,
                isStale: $stale > $staleAfterDays,
                reason: sprintf(
                    'No row in stock_prices or fund_navs on or before %s. Marked from '
                        .'stocks.last_price, dated %s (%d day%s earlier) — provisional, because no '
                        .'price history stands behind it.',
                    $asAt->toDateString(),
                    $date->toDateString(),
                    $stale,
                    $stale === 1 ? '' : 's',
                ),
            );
        }

        return $this->unpriced(
            $stockId,
            sprintf(
                'Nothing prices this scrip on or before %s — no close, no NAV, no last price. '
                    .'Excluded from every value figure rather than counted at cost.',
                $asAt->toDateString(),
            ),
        );
    }

    /**
     * Whether two sources disagree on the price of the same scrip on the same
     * date, beyond a tolerance.
     *
     * Reported rather than resolved. Where both a series row and a spot column
     * exist and differ, the series wins the *mark* — but silently preferring
     * one is how a divergence goes unnoticed for months, so the pair is handed
     * back for the caller to name.
     *
     * @return array{diverges: bool, difference: ?float, difference_percent: ?float}
     */
    public function divergence(?float $seriesPrice, ?float $spotPrice, float $tolerance = 0.01): array
    {
        if ($seriesPrice === null || $spotPrice === null || $seriesPrice <= 0.0) {
            return ['diverges' => false, 'difference' => null, 'difference_percent' => null];
        }

        $difference = round($spotPrice - $seriesPrice, 4);

        return [
            'diverges' => abs($difference) > $tolerance,
            'difference' => $difference,
            'difference_percent' => round($difference / $seriesPrice * 100, 4),
        ];
    }

    private function unpriced(int $stockId, string $reason): HoldingMark
    {
        return new HoldingMark(
            stockId: $stockId,
            price: null,
            source: HoldingMark::SOURCE_NONE,
            asOf: null,
            staleDays: null,
            seriesBacked: false,
            isStale: false,
            reason: $reason,
        );
    }

    /**
     * Carbon returns a float from diffInDays and an unrounded float in an
     * integer position has crashed a date calculation in this codebase before.
     */
    private function days(Carbon $from, Carbon $to): int
    {
        return (int) $from->diffInDays($to);
    }
}
