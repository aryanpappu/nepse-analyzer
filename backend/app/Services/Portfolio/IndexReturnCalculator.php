<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use Illuminate\Support\Carbon;

/**
 * The index-benchmark maths: pure functions over plain dated values and cash
 * flows. No Eloquent, no config lookups, no clock — every figure here is
 * provable by hand in a unit test the way `PerformanceService::rateOfReturn()`
 * and `InterestAccrualCalculator::accrue()` are.
 *
 * Cash-flow sign convention matches `PerformanceService::cashFlow()`
 * throughout: a flow is from the investor's pocket — negative for money going
 * in (a buy), positive for money coming out (a sell or dividend).
 */
final class IndexReturnCalculator
{
    /**
     * Index-level return over [$start, $end], both looked up "on or before"
     * so a NEPSE holiday or a scraper gap sitting on the boundary does not
     * fail the whole comparison.
     *
     * @return array{start_value: ?float, end_value: ?float, return_percent: ?float, complete: bool}
     */
    public function periodReturn(IndexSeries $series, Carbon $start, Carbon $end): array
    {
        $startValue = $series->valueOnOrBefore($start);
        $endValue = $series->valueOnOrBefore($end);

        if ($startValue === null || $endValue === null || $startValue <= 0.0) {
            return [
                'start_value' => $startValue,
                'end_value' => $endValue,
                'return_percent' => null,
                'complete' => false,
            ];
        }

        return [
            'start_value' => $startValue,
            'end_value' => $endValue,
            'return_percent' => round(($endValue - $startValue) / $startValue * 100, 2),
            'complete' => true,
        ];
    }

    /**
     * "What if every rupee that moved through the ledger had instead bought
     * index units at that date's level."
     *
     * Money in buys units at the flow date's level; money out redeems units
     * at that date's level and stops participating in the index from then on
     * — the same "earns until it's taken out, then stops" rule
     * `PerformanceService::fixedDepositValue()` applies to the FD benchmark,
     * expressed here as units rather than a compounding rate because the
     * index does not move at a fixed rate.
     *
     * A flow dated before the index series starts cannot be priced and is
     * excluded rather than assumed free or worthless; the caller sees exactly
     * which dates were skipped via `IndexSimulationResult::$uncoveredDates`
     * and can decide whether the comparison is trustworthy enough to show.
     *
     * @param  list<array{date: Carbon, amount: float}>  $flows  Order does not matter.
     */
    public function simulateInvestment(
        IndexSeries $series,
        array $flows,
        Carbon $end,
        float $openingValue = 0.0,
        ?Carbon $openingDate = null,
    ): IndexSimulationResult {
        $events = $flows;

        if ($openingValue > 0.0 && $openingDate !== null) {
            // The opening portfolio value behaves as a purchase on day one —
            // the same device fixedDepositValue() uses for a period's
            // opening capital.
            $events[] = ['date' => $openingDate, 'amount' => -$openingValue];
        }

        usort($events, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        $units = 0.0;
        $uncovered = [];

        foreach ($events as $event) {
            $amount = $event['amount'];

            if (abs($amount) < 1e-9) {
                continue;
            }

            $level = $series->valueOnOrBefore($event['date']);

            if ($level === null || $level <= 0.0) {
                $uncovered[] = $event['date']->toDateString();

                continue;
            }

            if ($amount < 0.0) {
                // A buy, from the investor's pocket: deposit into the index.
                $units += (-$amount) / $level;
            } else {
                // A sell or dividend: redemption, clamped at what the
                // simulation actually holds — mirroring the sell clamp in
                // PortfolioTimeSeries::positionsOn(). A withdrawal larger than
                // the simulated holding (e.g. a dividend paid before any
                // simulated purchase could be priced) cannot go negative.
                $units -= min($units, $amount / $level);
            }
        }

        $endLevel = $series->valueOnOrBefore($end);

        if ($endLevel === null) {
            return new IndexSimulationResult(value: null, unitsHeld: $units, complete: false, uncoveredDates: $uncovered);
        }

        return new IndexSimulationResult(
            value: round($units * $endLevel, 2),
            unitsHeld: $units,
            complete: $uncovered === [],
            uncoveredDates: $uncovered,
        );
    }

    /**
     * One sub-period's cash-flow-adjusted return, in the same shape
     * `PerformanceService::periodReturn()` uses for `simple_return_percent`:
     * profit over the capital actually at risk, not a raw value-to-value
     * ratio that would credit a mid-period deposit with performance it never
     * earned (or blame it for a fall it was never exposed to).
     *
     * $netContribution follows the same sign convention as the rest of this
     * class inverted to "money in is positive", matching how
     * PerformanceService reports `net_contribution` for a period.
     *
     * Null when there was no capital at risk in the sub-period at all — a
     * zero here would read as a flat interval rather than an absent one.
     */
    public function cashFlowAdjustedReturn(float $openingValue, float $closingValue, float $netContribution): ?float
    {
        $capitalBase = $openingValue + max(0.0, $netContribution);

        if ($capitalBase <= 0.0) {
            return null;
        }

        $profit = $closingValue - $openingValue - $netContribution;

        return round($profit / $capitalBase * 100, 4);
    }

    /**
     * Beta, correlation and alpha from paired per-interval returns.
     *
     * Beta is covariance over index variance — undefined (null) when the
     * index has zero variance across the sample, e.g. a flat index over the
     * whole window: there is no slope to fit, even though the portfolio's own
     * return is fully real and already visible in the money-weighted
     * comparison. Correlation is likewise null when either side has zero
     * variance. Alpha is the per-period intercept (mean portfolio return
     * minus beta times mean index return).
     *
     * @param  list<array{index: float, portfolio: float}>  $pairs  Return percent per interval, same window on both sides.
     */
    public function regression(array $pairs, string $interval, int $periodsPerYear): IndexRegressionResult
    {
        $n = count($pairs);

        if ($n < 2) {
            return new IndexRegressionResult($n, null, null, null, null, $interval, $periodsPerYear);
        }

        $meanX = array_sum(array_column($pairs, 'index')) / $n;
        $meanY = array_sum(array_column($pairs, 'portfolio')) / $n;

        $covariance = 0.0;
        $varianceX = 0.0;
        $varianceY = 0.0;

        foreach ($pairs as $pair) {
            $dx = $pair['index'] - $meanX;
            $dy = $pair['portfolio'] - $meanY;
            $covariance += $dx * $dy;
            $varianceX += $dx * $dx;
            $varianceY += $dy * $dy;
        }

        $covariance /= $n;
        $varianceX /= $n;
        $varianceY /= $n;

        if ($varianceX <= 1e-12) {
            return new IndexRegressionResult($n, null, null, null, null, $interval, $periodsPerYear);
        }

        $beta = $covariance / $varianceX;
        $correlation = $varianceY <= 1e-12 ? null : $covariance / sqrt($varianceX * $varianceY);
        $alpha = $meanY - ($beta * $meanX);

        return new IndexRegressionResult(
            sampleSize: $n,
            beta: round($beta, 4),
            correlation: $correlation === null ? null : round($correlation, 4),
            alphaPercentPerPeriod: round($alpha, 4),
            alphaPercentAnnualized: round($alpha * $periodsPerYear, 2),
            interval: $interval,
            periodsPerYear: $periodsPerYear,
        );
    }
}
