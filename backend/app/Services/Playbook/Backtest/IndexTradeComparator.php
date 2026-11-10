<?php

declare(strict_types=1);

namespace App\Services\Playbook\Backtest;

use App\Services\Portfolio\IndexReturnCalculator;
use App\Services\Portfolio\IndexSeries;
use Illuminate\Support\Carbon;

/**
 * Measures a bag of simulated trades against a market index.
 *
 * All index arithmetic is delegated to {@see IndexReturnCalculator} and
 * {@see IndexSeries} — `periodReturn()` for the matched windows and the
 * whole-window buy-and-hold, `simulateInvestment()` for the money-weighted
 * "bought the index on the same dates instead" figure. Writing a second set of
 * index maths here is exactly the drift the portfolio namespace already avoids
 * by making `PerformanceService::cashFlow()` public and static; this class
 * consumes those, it does not reimplement them.
 *
 * Pure apart from the two collaborators, both of which are themselves pure: it
 * is handed an already-loaded `IndexSeries` rather than a repository, so it is
 * unit-testable without a database.
 */
final class IndexTradeComparator
{
    /** Enough uncovered dates to see the shape of the gap without flooding a report. */
    private const UNCOVERED_SAMPLE = 12;

    public function __construct(
        private readonly IndexReturnCalculator $calculator,
    ) {}

    /**
     * The index's own return over one trade's entry-to-exit window, in
     * percent. Null when either boundary predates the series — the same
     * boundary rule `IndexSeries::valueOnOrBefore()` enforces, so a gap does
     * not silently become a 0% benchmark.
     */
    public function matchedReturn(IndexSeries $series, SimulatedTrade $trade): ?float
    {
        if ($trade->entryDate === null || $trade->exitDate === null) {
            return null;
        }

        $result = $this->calculator->periodReturn(
            $series,
            Carbon::parse($trade->entryDate),
            Carbon::parse($trade->exitDate),
        );

        return $result['complete'] ? $result['return_percent'] : null;
    }

    /**
     * @param  list<SimulatedTrade>  $trades  Any mix; only resolved ones are measured.
     */
    public function compare(
        IndexSeries $series,
        array $trades,
        Carbon $from,
        Carbon $to,
        string $indexSlug,
        ?string $indexName,
    ): IndexComparison {
        if ($series->isEmpty()) {
            return IndexComparison::unavailable($indexSlug, $indexName,
                sprintf('No index history stored for "%s" — no market comparison is possible.', $indexSlug));
        }

        $window = $this->calculator->periodReturn($series, $from, $to);
        $coverage = round($series->coverage($from, $to) * 100, 2);

        $matched = [];
        $uncovered = [];
        $flows = [];
        $strategyTerminal = 0.0;
        $deployed = 0.0;

        foreach ($trades as $trade) {
            if (! $trade->isResolved()) {
                continue;
            }

            $indexReturn = $this->matchedReturn($series, $trade);

            if ($indexReturn === null) {
                $uncovered[] = $trade->entryDate ?? $trade->signalDate;

                continue;
            }

            $matched[] = ['index' => $indexReturn, 'trade' => $trade];

            $capital = (float) $trade->capitalDeployed();
            $deployed += $capital;
            $strategyTerminal += $capital + (float) $trade->netProfit;

            // "Had this rupee bought index units on the entry date and simply
            // stayed there" — one outflow per trade, no redemption, valued at
            // the window end by simulateInvestment().
            $flows[] = ['date' => Carbon::parse((string) $trade->entryDate), 'amount' => -$capital];
        }

        if ($matched === []) {
            return new IndexComparison(
                indexSlug: $indexSlug,
                indexName: $indexName,
                windowReturnPercent: $window['return_percent'],
                windowComplete: $window['complete'],
                coveragePercent: $coverage,
                matchedTrades: 0,
                unmatchedTrades: count($uncovered),
                averageIndexReturnPercent: null,
                averageStrategyNetReturnPercent: null,
                averageStrategyGrossReturnPercent: null,
                averageExcessNetPercent: null,
                averageExcessGrossPercent: null,
                winVersusIndexPercent: null,
                strategyTerminalValue: null,
                indexBuyAndHoldValue: null,
                terminalDifference: null,
                uncoveredDates: array_slice(array_values(array_unique($uncovered)), 0, self::UNCOVERED_SAMPLE),
            );
        }

        $n = count($matched);
        $indexSum = 0.0;
        $netSum = 0.0;
        $grossSum = 0.0;
        $wins = 0;

        foreach ($matched as $pair) {
            $trade = $pair['trade'];
            $indexSum += $pair['index'];
            $netSum += (float) $trade->netReturnPercent;
            $grossSum += (float) $trade->grossReturnPercent;

            if ((float) $trade->netReturnPercent > $pair['index']) {
                $wins++;
            }
        }

        $simulation = $this->calculator->simulateInvestment($series, $flows, $to);

        return new IndexComparison(
            indexSlug: $indexSlug,
            indexName: $indexName,
            windowReturnPercent: $window['return_percent'],
            windowComplete: $window['complete'],
            coveragePercent: $coverage,
            matchedTrades: $n,
            unmatchedTrades: count($uncovered),
            averageIndexReturnPercent: round($indexSum / $n, 4),
            averageStrategyNetReturnPercent: round($netSum / $n, 4),
            averageStrategyGrossReturnPercent: round($grossSum / $n, 4),
            averageExcessNetPercent: round(($netSum - $indexSum) / $n, 4),
            averageExcessGrossPercent: round(($grossSum - $indexSum) / $n, 4),
            winVersusIndexPercent: round($wins / $n * 100, 2),
            strategyTerminalValue: round($strategyTerminal, 2),
            indexBuyAndHoldValue: $simulation->value,
            terminalDifference: $simulation->value === null ? null : round($strategyTerminal - $simulation->value, 2),
            uncoveredDates: array_slice(array_values(array_unique($uncovered)), 0, self::UNCOVERED_SAMPLE),
        );
    }
}
