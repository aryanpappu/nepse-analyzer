<?php

declare(strict_types=1);

namespace App\Services\Playbook\Backtest;

/**
 * How much of the window the backtest could actually see.
 *
 * In a fictional benchmark, 60% index coverage suggests a gain against the
 * index of NPR 2,000.00 while complete history shows a loss of NPR 1,500.00.
 * The 3,500.00 swing comes entirely from the unmeasured stretch. A
 * backtest is more exposed, not less: it has a bar-coverage hole, an
 * indicator-coverage hole, a fundamentals hole, an index hole, and an unresolved
 * -trade hole, and any one of them can carry the result.
 *
 * Every percentage is nullable and stays null when its denominator is zero.
 */
final class BacktestCoverage
{
    /**
     * @param  array<string, int>  $excludedByInstrument  instrument_type => count of stocks not scored.
     * @param  array<string, int>  $skipReasons  Why a (stock, date, profile) produced no plan.
     * @param  list<string>  $indexUncoveredDates  Trade dates the index series could not price (capped).
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly int $sessionsObserved,
        public readonly string $sessionSource,
        public readonly int $signalDates,
        /** @var list<string> Blank dates wedged between two observed sessions — likely crawler gaps, not closures. */
        public readonly array $suspectedCalendarGaps,
        public readonly ?string $latestBarDate,
        public readonly int $stocksEligible,
        public readonly int $stocksScored,
        public readonly array $excludedByInstrument,
        public readonly int $stockDatePairsPossible,
        public readonly int $stockDatePairsWithBar,
        public readonly int $stockDatePairsWithIndicator,
        public readonly int $stockDatePairsWithFundamental,
        public readonly array $skipReasons,
        public readonly int $verdicts,
        public readonly int $verdictsWithoutGeometry,
        public readonly int $plansSimulated,
        public readonly int $tradesResolved,
        public readonly int $tradesNoFill,
        public readonly int $tradesUnresolved,
        public readonly ?float $indexCoveragePercent,
        public readonly bool $indexSimulationComplete,
        public readonly array $indexUncoveredDates,
        public readonly string $indexCoverageNote,
        public readonly string $fundamentalsMode,
        public readonly bool $lookAheadAllowed,
    ) {}

    public function barCoveragePercent(): ?float
    {
        return $this->stockDatePairsPossible === 0
            ? null
            : round($this->stockDatePairsWithBar / $this->stockDatePairsPossible * 100, 2);
    }

    /**
     * Of the (stock, date) pairs that had a bar, how many also had an
     * indicator row dated on or before that date. Below 100% the engine's
     * trend and momentum buckets were null and their weight redistributed —
     * i.e. a different scoring model ran on those pairs.
     */
    public function indicatorCoveragePercent(): ?float
    {
        return $this->stockDatePairsWithBar === 0
            ? null
            : round($this->stockDatePairsWithIndicator / $this->stockDatePairsWithBar * 100, 2);
    }

    public function fundamentalCoveragePercent(): ?float
    {
        return $this->stockDatePairsWithBar === 0
            ? null
            : round($this->stockDatePairsWithFundamental / $this->stockDatePairsWithBar * 100, 2);
    }

    /**
     * The figure that decides whether the performance numbers mean anything:
     * how many of the plans the engine emitted reached a stop, a target or the
     * end of their hold window inside the data. Everything else is n/a.
     */
    public function resolutionPercent(): ?float
    {
        return $this->plansSimulated === 0
            ? null
            : round($this->tradesResolved / $this->plansSimulated * 100, 2);
    }

    /**
     * One paragraph a human can act on, in the same spirit as
     * `index_coverage_note` on the portfolio benchmark.
     */
    public function note(): string
    {
        $parts = [];

        $bar = $this->barCoveragePercent();
        $parts[] = $bar === null
            ? 'No (stock, date) pairs were even possible — the window contains no observed sessions.'
            : sprintf('%s%% of the %s possible (scrip, signal date) pairs had an observed bar.',
                number_format($bar, 1), number_format($this->stockDatePairsPossible));

        $indicators = $this->indicatorCoveragePercent();

        if ($indicators !== null) {
            $parts[] = sprintf('%s%% of those had an indicator row dated on or before the signal.',
                number_format($indicators, 1));
        }

        $resolution = $this->resolutionPercent();

        if ($resolution !== null) {
            $parts[] = sprintf('%s%% of the %s plans emitted resolved inside the available data (%s never filled, %s ran past the last stored bar and are reported as n/a, not as flat trades).',
                number_format($resolution, 1),
                number_format($this->plansSimulated),
                number_format($this->tradesNoFill),
                number_format($this->tradesUnresolved));
        }

        if ($this->suspectedCalendarGaps !== []) {
            $parts[] = sprintf(
                '%d date(s) inside the window have no bar at all yet sit between two consecutive observed '
                .'sessions (%s) — most likely a crawler gap rather than a closure. They are excluded from the '
                .'session count and from every hold-length measurement, and are NOT recorded as holidays.',
                count($this->suspectedCalendarGaps),
                implode(', ', array_slice($this->suspectedCalendarGaps, 0, 6)),
            );
        }

        $parts[] = $this->indexCoverageNote;

        if ($this->lookAheadAllowed) {
            $parts[] = 'LOOK-AHEAD WAS ALLOWED for fundamentals: these figures are not a forecast of live performance.';
        }

        return implode(' ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'sessions_observed' => $this->sessionsObserved,
            'session_source' => $this->sessionSource,
            'signal_dates' => $this->signalDates,
            'suspected_calendar_gaps' => $this->suspectedCalendarGaps,
            'latest_bar_date' => $this->latestBarDate,
            'stocks_eligible' => $this->stocksEligible,
            'stocks_scored' => $this->stocksScored,
            'excluded_by_instrument' => $this->excludedByInstrument,
            'stock_date_pairs_possible' => $this->stockDatePairsPossible,
            'stock_date_pairs_with_bar' => $this->stockDatePairsWithBar,
            'bar_coverage_percent' => $this->barCoveragePercent(),
            'indicator_coverage_percent' => $this->indicatorCoveragePercent(),
            'fundamental_coverage_percent' => $this->fundamentalCoveragePercent(),
            'fundamentals_mode' => $this->fundamentalsMode,
            'look_ahead_allowed' => $this->lookAheadAllowed,
            'skip_reasons' => $this->skipReasons,
            'verdicts' => $this->verdicts,
            'verdicts_without_geometry' => $this->verdictsWithoutGeometry,
            'plans_simulated' => $this->plansSimulated,
            'trades_resolved' => $this->tradesResolved,
            'trades_no_fill' => $this->tradesNoFill,
            'trades_unresolved' => $this->tradesUnresolved,
            'resolution_percent' => $this->resolutionPercent(),
            'index_coverage_percent' => $this->indexCoveragePercent,
            'index_simulation_complete' => $this->indexSimulationComplete,
            'index_uncovered_dates' => $this->indexUncoveredDates,
            'index_coverage_note' => $this->indexCoverageNote,
            'note' => $this->note(),
        ];
    }
}
