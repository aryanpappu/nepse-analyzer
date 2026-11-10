<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Models\Portfolio;
use App\Models\PortfolioHolding;
use App\Models\PortfolioTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Answers "did the portfolio beat the market", the question
 * `PerformanceService::fixedDepositComparison()` never asks — a year down 5%
 * while NEPSE fell 15% is a good year, and nothing else here says so.
 *
 * Every comparison mirrors the fixed-deposit benchmark's design rather than
 * inventing new rules: the same cash flows (`PerformanceService::cashFlow()`),
 * the same "money earns until it's taken out, then stops" treatment of
 * deposits and withdrawals, and the same "a boundary the data cannot reach is
 * n/a, never zero" honesty rule `PortfolioTimeSeries` and the README both
 * insist on.
 *
 * `market_indices` is populated by a separate scraper (0 rows as of writing),
 * so every method here must degrade rather than assume the data exists:
 * an index with no rows, a gap at the start of the window, or a sector with
 * no mapped sub-index all report `null` figures plus a plain-English note,
 * never a silent 0%.
 *
 * Config this service reads (add to `config/nepse.php` if not already
 * present — this class only owns sensible fallbacks, not the config file):
 * - `nepse.benchmark.market_index_slug` (default `'nepse'`) — the whole-market
 *   index slug used when a caller does not name one.
 * - `nepse.benchmark.sector_index_slugs` (default `[]`) — sector slug =>
 *   sub-index slug, for holdings judged against their own sector rather than
 *   the whole market. Sectors absent from the map fall back to trying the
 *   sector's own slug as the index slug, which degrades to "no sub-index
 *   data" when the scraper has not stored anything under that name.
 */
final class IndexBenchmarkService
{
    /** Sample the portfolio/index value every trading day. Expensive — see riskMetrics(). */
    public const INTERVAL_DAILY = 'daily';

    /** Default interval for risk metrics. */
    public const INTERVAL_WEEKLY = 'weekly';

    public const INTERVAL_MONTHLY = 'monthly';

    /** Calendar-day step used to build the sample dates for each interval. */
    private const INTERVAL_STEP_DAYS = [
        self::INTERVAL_DAILY => 1,
        self::INTERVAL_WEEKLY => 7,
        self::INTERVAL_MONTHLY => 30,
    ];

    /** Used only to annualise alpha; NEPSE trades roughly 5 days a week. */
    private const INTERVAL_PERIODS_PER_YEAR = [
        self::INTERVAL_DAILY => 252,
        self::INTERVAL_WEEKLY => 52,
        self::INTERVAL_MONTHLY => 12,
    ];

    public function __construct(
        private readonly PortfolioTimeSeries $timeSeries = new PortfolioTimeSeries,
        private readonly IndexReturnCalculator $calculator = new IndexReturnCalculator,
        private readonly PerformanceService $performance = new PerformanceService(new PortfolioValuationService),
        private readonly IndexSeriesRepository $indices = new IndexSeriesRepository,
    ) {}

    // ------------------------------------------------------------------
    // 1. "What if I had bought the index instead" — money-weighted
    // ------------------------------------------------------------------

    /**
     * The direct analogue of `PerformanceService::fixedDepositComparison()`:
     * the portfolio's actual cash flows, on their actual dates, replayed as
     * index-unit purchases and redemptions instead of a compounding deposit
     * rate. Money put in works until $asOf; money taken out stops working the
     * day it was taken out.
     *
     * @return array<string, mixed>
     */
    public function marketWeightedComparison(Portfolio $portfolio, ?string $indexSlug = null, ?Carbon $asOf = null): array
    {
        $slug = $this->resolveMarketSlug($indexSlug);
        $end = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $transactions = $this->ledger($portfolio);
        $first = $transactions->first()?->transacted_on;

        if ($first === null) {
            return $this->emptyMarketComparison($slug, $end);
        }

        ['series' => $series, 'name' => $indexName] = $this->loadSeries($slug, $end);
        $flows = $this->flowsFrom($transactions);
        $portfolioMark = $this->timeSeries->valueOn($portfolio, $end, $transactions);
        $simulation = $this->calculator->simulateInvestment($series, $flows, $end);

        $portfolioValue = ($portfolioMark['known'] ?? false) ? $portfolioMark['value'] : null;
        $indexValue = $simulation->known() ? $simulation->value : null;

        $valueVsIndex = ($portfolioValue !== null && $indexValue !== null)
            ? round($portfolioValue - $indexValue, 2)
            : null;
        $valueVsIndexPercent = ($valueVsIndex !== null && $indexValue > 0.0)
            ? round($valueVsIndex / $indexValue * 100, 2)
            : null;

        return [
            'index_slug' => $slug,
            'index_name' => $indexName,
            'as_of' => $end->toDateString(),
            'since' => $first->toDateString(),
            'index_available' => ! $series->isEmpty(),
            'index_coverage_percent' => round($series->coverage($first, $end) * 100, 2),
            'index_coverage_note' => $this->coverageNote($indexName, $series, $first, $end),
            'portfolio_value' => $portfolioValue,
            'portfolio_value_known' => $portfolioMark['known'] ?? false,
            'portfolio_unpriced_symbols' => $portfolioMark['unpriced_symbols'] ?? [],
            'index_value' => $indexValue,
            'index_simulation_complete' => $simulation->complete,
            'index_uncovered_dates' => $simulation->uncoveredDates,
            'value_vs_index' => $valueVsIndex,
            'value_vs_index_percent' => $valueVsIndexPercent,
            'beat_index' => $valueVsIndex === null ? null : $valueVsIndex > 0.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyMarketComparison(string $slug, Carbon $end): array
    {
        return [
            'index_slug' => $slug,
            'index_name' => null,
            'as_of' => $end->toDateString(),
            'since' => null,
            'index_available' => false,
            'index_coverage_percent' => 0.0,
            'index_coverage_note' => 'No transactions recorded yet; there is nothing to benchmark.',
            'portfolio_value' => 0.0,
            'portfolio_value_known' => true,
            'portfolio_unpriced_symbols' => [],
            'index_value' => null,
            'index_simulation_complete' => false,
            'index_uncovered_dates' => [],
            'value_vs_index' => null,
            'value_vs_index_percent' => null,
            'beat_index' => null,
        ];
    }

    // ------------------------------------------------------------------
    // 2. Period return comparison, reusing the existing FY/calendar buckets
    // ------------------------------------------------------------------

    /**
     * Portfolio return vs index return over the same fiscal-year or
     * calendar-year windows `PerformanceService::report()` already buckets —
     * this deliberately reuses that bucketing (and its `simple_return_percent`,
     * a non-annualised figure so a part-year stays honest) rather than
     * re-deriving fiscal-year boundaries a second time.
     *
     * @return array<string, mixed>
     */
    public function periodComparison(
        Portfolio $portfolio,
        ?string $indexSlug = null,
        string $basis = PerformanceService::BASIS_FISCAL,
    ): array {
        $slug = $this->resolveMarketSlug($indexSlug);
        $basis = in_array($basis, [PerformanceService::BASIS_CALENDAR, PerformanceService::BASIS_FISCAL], true)
            ? $basis
            : PerformanceService::BASIS_FISCAL;

        $periods = $this->performance->report($portfolio, $basis)['periods'];

        if ($periods === []) {
            return ['index_slug' => $slug, 'index_name' => null, 'basis' => $basis, 'index_coverage_note' => null, 'periods' => []];
        }

        $today = Carbon::today();
        ['series' => $series, 'name' => $indexName] = $this->loadSeries($slug, $today);

        $rows = [];

        foreach ($periods as $period) {
            $start = Carbon::parse($period['starts_on'])->startOfDay();
            $end = $this->periodEffectiveEnd($period['ends_on']);

            // The portfolio's own opening mark for this period is taken the
            // day before it starts (see PerformanceService::periods()); the
            // index side mirrors that so both boundaries mean the same thing.
            $indexReturn = $this->calculator->periodReturn($series, $start->copy()->subDay(), $end);

            $portfolioReturn = $period['simple_return_percent'];
            $excess = ($portfolioReturn !== null && $indexReturn['return_percent'] !== null)
                ? round($portfolioReturn - $indexReturn['return_percent'], 2)
                : null;

            $rows[] = [
                'key' => $period['key'],
                'label' => $period['label'],
                'starts_on' => $period['starts_on'],
                'ends_on' => $period['ends_on'],
                'is_current' => $period['is_current'],
                'is_partial' => $period['is_partial'],
                'portfolio_return_percent' => $portfolioReturn,
                'index_start_value' => $indexReturn['start_value'],
                'index_end_value' => $indexReturn['end_value'],
                'index_return_percent' => $indexReturn['return_percent'],
                'index_data_complete' => $indexReturn['complete'],
                'excess_return_percent' => $excess,
                'beat_index' => $excess === null ? null : $excess > 0.0,
            ];
        }

        // Periods come back newest-first from PerformanceService::report().
        $earliestStart = Carbon::parse($periods[array_key_last($periods)]['starts_on']);

        return [
            'index_slug' => $slug,
            'index_name' => $indexName,
            'basis' => $basis,
            'index_coverage_note' => $this->coverageNote($indexName, $series, $earliestStart, $today),
            'periods' => $rows,
        ];
    }

    /**
     * A period never ends in the future — mirrors PerformanceService::periodEnd()
     * exactly, duplicated rather than shared because it is a one-line clamp
     * and not worth reaching into a private method of another service for.
     */
    private function periodEffectiveEnd(string $endsOn): Carbon
    {
        $end = Carbon::parse($endsOn)->startOfDay();
        $today = Carbon::today();

        return $end->greaterThan($today) ? $today : $end;
    }

    // ------------------------------------------------------------------
    // 3. Beta, correlation, alpha
    // ------------------------------------------------------------------

    /**
     * Beta/correlation/alpha of the portfolio against the index, sampled at
     * $interval rather than every trading day.
     *
     * Cost note: `PortfolioTimeSeries::valuesOn()` issues one SQL query per
     * sample date (it replays the ledger once, but marks each date against
     * `stock_prices`/`fund_navs` separately). A multi-year *daily* replay is
     * many hundreds of round trips per request and is not worth it for a
     * statistic that is already noisy at daily granularity. Weekly (the
     * default here) keeps a 2-year window to ~104 queries; monthly keeps a
     * decade to ~120. `INTERVAL_DAILY` is left available for a short,
     * deliberate window (e.g. "this quarter") where the extra queries are
     * cheap and the granularity is worth it — callers should not default to
     * it over a multi-year range.
     *
     * Each interval's portfolio return is cash-flow-adjusted with the same
     * profit-over-capital-at-risk formula `PerformanceService::periodReturn()`
     * uses for `simple_return_percent`, so a deposit or withdrawal mid-window
     * does not get credited (or blamed for) a move it was never exposed to.
     *
     * @return array<string, mixed>
     */
    public function riskMetrics(
        Portfolio $portfolio,
        ?string $indexSlug = null,
        string $interval = self::INTERVAL_WEEKLY,
        ?Carbon $from = null,
        ?Carbon $to = null,
    ): array {
        $slug = $this->resolveMarketSlug($indexSlug);
        $interval = array_key_exists($interval, self::INTERVAL_STEP_DAYS) ? $interval : self::INTERVAL_WEEKLY;

        $transactions = $this->ledger($portfolio);
        $first = $transactions->first()?->transacted_on;

        if ($first === null) {
            return $this->emptyRiskMetrics($slug, $interval);
        }

        $to = ($to ?? Carbon::today())->copy()->startOfDay();
        $from = ($from ?? $first)->copy()->startOfDay();
        $from = $from->lessThan($first) ? $first->copy()->startOfDay() : $from;

        if ($from->greaterThanOrEqualTo($to)) {
            return $this->emptyRiskMetrics($slug, $interval);
        }

        $sampleDates = $this->sampleDates($from, $to, self::INTERVAL_STEP_DAYS[$interval]);

        if (count($sampleDates) < 2) {
            return $this->emptyRiskMetrics($slug, $interval);
        }

        $portfolioMarks = $this->timeSeries->valuesOn($portfolio, $sampleDates);
        ['series' => $series, 'name' => $indexName] = $this->loadSeries($slug, $to);
        $flows = $this->flowsFrom($transactions);

        $pairs = [];

        for ($i = 0; $i < count($sampleDates) - 1; $i++) {
            $start = $sampleDates[$i];
            $end = $sampleDates[$i + 1];

            $startMark = $portfolioMarks[$start->toDateString()] ?? null;
            $endMark = $portfolioMarks[$end->toDateString()] ?? null;

            if (! ($startMark['complete'] ?? false) || ! ($endMark['complete'] ?? false)) {
                // An unpriced holding at either boundary would silently
                // invent a return; skip the interval instead.
                continue;
            }

            // Flows strictly after $start and up to $end: the mark AT $start
            // already reflects anything transacted on or before it, so
            // counting a same-day flow again here would double-count it.
            $netContribution = -$this->flowsBetween($flows, $start, $end);
            $portfolioReturn = $this->calculator->cashFlowAdjustedReturn(
                $startMark['value'], $endMark['value'], $netContribution,
            );
            $indexReturn = $this->calculator->periodReturn($series, $start, $end)['return_percent'];

            if ($portfolioReturn === null || $indexReturn === null) {
                continue;
            }

            $pairs[] = ['index' => $indexReturn, 'portfolio' => $portfolioReturn];
        }

        $regression = $this->calculator->regression($pairs, $interval, self::INTERVAL_PERIODS_PER_YEAR[$interval]);

        return $regression->toArray() + [
            'index_slug' => $slug,
            'index_name' => $indexName,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'requested_intervals' => count($sampleDates) - 1,
            'index_coverage_percent' => round($series->coverage($from, $to) * 100, 2),
            'index_coverage_note' => $this->coverageNote($indexName, $series, $from, $to),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyRiskMetrics(string $slug, string $interval): array
    {
        $empty = new IndexRegressionResult(0, null, null, null, null, $interval, self::INTERVAL_PERIODS_PER_YEAR[$interval]);

        return $empty->toArray() + [
            'index_slug' => $slug,
            'index_name' => null,
            'from' => null,
            'to' => null,
            'requested_intervals' => 0,
            'index_coverage_percent' => 0.0,
            'index_coverage_note' => 'Not enough transaction history to build a return series.',
        ];
    }

    /**
     * @return list<Carbon>
     */
    private function sampleDates(Carbon $from, Carbon $to, int $stepDays): array
    {
        $dates = [];
        $cursor = $from->copy();

        while ($cursor->lessThan($to)) {
            $dates[] = $cursor->copy();
            $cursor = $cursor->addDays($stepDays);
        }

        $dates[] = $to->copy();

        return $dates;
    }

    /**
     * @param  list<array{date: Carbon, amount: float}>  $flows
     */
    private function flowsBetween(array $flows, Carbon $start, Carbon $end): float
    {
        $sum = 0.0;

        foreach ($flows as $flow) {
            if ($flow['date']->greaterThan($start) && $flow['date']->lessThanOrEqualTo($end)) {
                $sum += $flow['amount'];
            }
        }

        return $sum;
    }

    // ------------------------------------------------------------------
    // 4. Sector-level benchmarking
    // ------------------------------------------------------------------

    /**
     * Each held sector against its own sub-index rather than the whole
     * market — a banking holding is not fairly judged against a NEPSE index
     * that is a third hydropower. Degrades per sector: unclassified holdings,
     * a sector with no mapped sub-index, and a mapped sub-index with no
     * scraped rows all report a null benchmark with a reason, never a 0%.
     *
     * @return array<string, mixed>
     */
    public function sectorComparison(Portfolio $portfolio, ?Carbon $asOf = null): array
    {
        $end = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $holdings = $portfolio->openHoldings()->with('stock.sector')->get();

        if ($holdings->isEmpty()) {
            return ['as_of' => $end->toDateString(), 'sectors' => []];
        }

        /** @var array<string, array{name: string, slug: ?string, holdings: list<PortfolioHolding>}> $bySector */
        $bySector = [];

        foreach ($holdings as $holding) {
            $sector = $holding->stock?->sector;
            $key = $sector?->slug ?? 'unclassified';

            $bySector[$key] ??= ['name' => $sector?->name ?? 'Unclassified', 'slug' => $sector?->slug, 'holdings' => []];
            $bySector[$key]['holdings'][] = $holding;
        }

        $rows = [];

        foreach ($bySector as $group) {
            $rows[] = $this->sectorRow($portfolio, $group, $end);
        }

        usort($rows, static fn (array $a, array $b): int => $b['market_value'] <=> $a['market_value']);

        return ['as_of' => $end->toDateString(), 'sectors' => $rows];
    }

    /**
     * @param  array{name: string, slug: ?string, holdings: list<PortfolioHolding>}  $group
     * @return array<string, mixed>
     */
    private function sectorRow(Portfolio $portfolio, array $group, Carbon $end): array
    {
        $invested = 0.0;
        $marketValue = 0.0;
        $unpriced = [];
        $symbols = [];
        $stockIds = [];

        foreach ($group['holdings'] as $holding) {
            $symbols[] = $holding->stock->symbol;
            $stockIds[] = $holding->stock_id;
            $invested += $holding->investedAmount();
            $value = $holding->currentValue();

            if ($value === null) {
                $unpriced[] = $holding->stock->symbol;
            } else {
                $marketValue += $value;
            }
        }

        $base = [
            'sector' => $group['name'],
            'sector_slug' => $group['slug'],
            'holdings_count' => count($group['holdings']),
            'symbols' => $symbols,
            'invested' => round($invested, 2),
            'market_value' => round($marketValue, 2),
            'unpriced_symbols' => $unpriced,
        ];

        if ($group['slug'] === null) {
            return $base + $this->noBenchmark(null, 'This holding has no classified sector, so no sub-index applies.');
        }

        $indexSlug = $this->subIndexSlugFor($group['slug']);
        ['series' => $series, 'name' => $indexName] = $this->loadSeries($indexSlug, $end);

        if ($series->isEmpty()) {
            return $base + $this->noBenchmark(
                $indexSlug,
                sprintf('No data yet for the %s sub-index (slug "%s").', $group['name'], $indexSlug),
            );
        }

        $transactions = $portfolio->transactions()
            ->whereIn('stock_id', $stockIds)
            ->orderBy('transacted_on')
            ->orderBy('id')
            ->get();

        $first = $transactions->first()?->transacted_on;

        if ($first === null) {
            return $base + $this->noBenchmark($indexSlug, null, $indexName);
        }

        $flows = $this->flowsFrom($transactions);
        $simulation = $this->calculator->simulateInvestment($series, $flows, $end);
        $indexValue = $simulation->known() ? $simulation->value : null;
        $valueVsIndex = $indexValue === null ? null : round($marketValue - $indexValue, 2);
        $valueVsIndexPercent = ($valueVsIndex !== null && $indexValue > 0.0)
            ? round($valueVsIndex / $indexValue * 100, 2)
            : null;

        return $base + [
            'index_slug' => $indexSlug,
            'index_name' => $indexName,
            'index_value' => $indexValue,
            'index_simulation_complete' => $simulation->complete,
            'index_uncovered_dates' => $simulation->uncoveredDates,
            'value_vs_index' => $valueVsIndex,
            'value_vs_index_percent' => $valueVsIndexPercent,
            'beat_index' => $valueVsIndex === null ? null : $valueVsIndex > 0.0,
            'index_coverage_note' => $this->coverageNote($indexName, $series, $first, $end),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function noBenchmark(?string $indexSlug, ?string $note, ?string $indexName = null): array
    {
        return [
            'index_slug' => $indexSlug,
            'index_name' => $indexName,
            'index_value' => null,
            'index_simulation_complete' => false,
            'index_uncovered_dates' => [],
            'value_vs_index' => null,
            'value_vs_index_percent' => null,
            'beat_index' => null,
            'index_coverage_note' => $note,
        ];
    }

    /**
     * Sub-index slug for a sector, from the configured map, falling back to
     * the sector's own slug — which itself degrades to "no sub-index data"
     * via loadSeries() returning an empty series when nothing is scraped
     * under that name.
     */
    private function subIndexSlugFor(string $sectorSlug): string
    {
        return $this->indices->subIndexSlugFor($sectorSlug);
    }

    // ------------------------------------------------------------------
    // Shared plumbing
    // ------------------------------------------------------------------

    private function resolveMarketSlug(?string $indexSlug): string
    {
        return $this->indices->marketSlug($indexSlug);
    }

    /**
     * @return Collection<int, PortfolioTransaction>
     */
    private function ledger(Portfolio $portfolio): Collection
    {
        return $portfolio->transactions()
            ->with('stock:id,symbol')
            ->orderBy('transacted_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * Cash flows in the exact convention `PerformanceService::cashFlow()`
     * defines: negative for money invested, positive for money returned.
     * Reusing that method rather than re-deriving the same rule is what
     * makes this benchmark a fair comparison against the same reality the FD
     * benchmark already compares against.
     *
     * @param  Collection<int, PortfolioTransaction>  $transactions
     * @return list<array{date: Carbon, amount: float}>
     */
    private function flowsFrom(Collection $transactions): array
    {
        $flows = [];

        foreach ($transactions as $transaction) {
            $amount = PerformanceService::cashFlow($transaction);

            if (abs($amount) > 1e-9) {
                $flows[] = ['date' => $transaction->transacted_on->copy(), 'amount' => $amount];
            }
        }

        return $flows;
    }

    /**
     * All levels of $slug up to $upTo. Delegated to
     * {@see IndexSeriesRepository} so this service and
     * {@see ReturnAttributionService} cannot end up measuring against two
     * differently-built series of the same index.
     *
     * @return array{series: IndexSeries, name: ?string}
     */
    private function loadSeries(string $slug, Carbon $upTo): array
    {
        return $this->indices->load($slug, $upTo);
    }

    /**
     * Plain-English explanation of what part of [$from, $to] the index data
     * actually covers, so a caller can show "benchmark unavailable before
     * 2024" instead of implying a complete comparison. Null when coverage is
     * complete — nothing to say.
     */
    private function coverageNote(?string $indexName, IndexSeries $series, Carbon $from, Carbon $to): ?string
    {
        $label = $indexName ?? 'The index';

        if ($series->isEmpty()) {
            return sprintf('%s has no data yet; this comparison could not be computed.', $label);
        }

        $earliest = $series->earliestDate();

        if ($earliest !== null && $earliest->greaterThan($from)) {
            return sprintf('%s data starts %s, so the window before that date is not covered.', $label, $earliest->toDateString());
        }

        $latest = $series->latestDate();

        if ($latest !== null && $latest->lessThan($to)) {
            return sprintf('%s data only runs to %s.', $label, $latest->toDateString());
        }

        return null;
    }
}
