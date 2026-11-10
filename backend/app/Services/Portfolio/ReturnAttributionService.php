<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * *Why* the portfolio trails (or beats) the market, not just by how much.
 *
 * `IndexBenchmarkService` answers "did stock-picking add anything" with one
 * rupee figure, a beta and an alpha. A fictional example has beta 1.10,
 * alpha −2.00% annualised and NPR 1,500.00 of index underperformance —
 * more risk for less return, with no indication of which holdings or sectors
 * caused it. This service decomposes the same window:
 *
 * 1. **Per-holding contribution.** weight × return per scrip, summing exactly
 *    to the portfolio's return over the window — the reconciliation is an
 *    invariant of {@see ReturnAttributionCalculator}, tested the way the
 *    playbook's stop/target invariants are.
 * 2. **Per-sector selection.** Each sector's own return against its
 *    sub-index's return over the same dates. Like-for-like, and needing no
 *    benchmark weights at all.
 * 3. **Allocation vs selection (Brinson).** Reported as unavailable, with the
 *    measured reason — see {@see benchmarkWeightAvailability()}. Benchmark
 *    weights need a market cap *as at the window start*, and while
 *    `stock_fundamentals.outstanding_shares` covers most of the market, every
 *    row's `reported_on` is null and `fiscal_period` is a non-uniform BS
 *    string, so no share count here can be dated. Undated weights applied to
 *    a dated window would let the period's own returns leak into the
 *    allocation effect, which is precisely the error that makes a Brinson
 *    attribution worse than none.
 *
 * Everything is derived from data already in the database — the ledger, stored
 * closes and NAVs, and the scraped index history. No LLM call, no new scrape.
 */
final class ReturnAttributionService
{
    public function __construct(
        private readonly PortfolioTimeSeries $timeSeries = new PortfolioTimeSeries,
        private readonly IndexSeriesRepository $indices = new IndexSeriesRepository,
        private readonly IndexReturnCalculator $indexReturns = new IndexReturnCalculator,
        private readonly ReturnAttributionCalculator $calculator = new ReturnAttributionCalculator,
    ) {}

    /**
     * @param  Carbon|null  $from  Defaults to the day before the first transaction, so the whole history is attributed.
     * @param  Carbon|null  $to  Defaults to today.
     * @return array<string, mixed>
     */
    public function report(Portfolio $portfolio, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $transactions = $this->ledger($portfolio);
        $first = $transactions->first()?->transacted_on;
        $end = ($to ?? Carbon::today())->copy()->startOfDay();

        if ($first === null) {
            return $this->empty($end, 'This portfolio has no transactions, so there is no return to attribute.');
        }

        // One day before the first trade: the opening position is then empty,
        // so the first purchase is attributed as a contribution rather than
        // as opening capital that appeared from nowhere.
        $start = ($from ?? $first->copy()->subDay())->copy()->startOfDay();

        if ($start->gte($end)) {
            return $this->empty($end, 'The window start must be before the window end.');
        }

        $opening = $this->timeSeries->positionValuesOn($transactions, $start);
        $closing = $this->timeSeries->positionValuesOn($transactions, $end);
        $flows = $this->contributionsByStock($transactions, $start, $end);

        $stockIds = array_values(array_unique([
            ...array_keys($opening),
            ...array_keys($closing),
            ...array_keys($flows),
        ]));

        $meta = $this->stockMeta($stockIds);
        $positions = [];

        foreach ($stockIds as $stockId) {
            $info = $meta[$stockId] ?? null;

            if ($info === null) {
                continue;
            }

            $positions[] = [
                'symbol' => $info['symbol'],
                'sector' => $info['sector'],
                'sector_slug' => $info['sector_slug'],
                // Absent from a boundary means the position did not exist
                // then — a real zero. Present but unpriced means unknown,
                // and the calculator excludes and names it.
                'opening_value' => array_key_exists($stockId, $opening) ? $opening[$stockId]['value'] : 0.0,
                'closing_value' => array_key_exists($stockId, $closing) ? $closing[$stockId]['value'] : 0.0,
                'net_contribution' => $flows[$stockId] ?? 0.0,
            ];
        }

        $attribution = $this->calculator->contributions($positions);
        $excludedSymbols = array_column($attribution['excluded'], 'symbol');

        return [
            'window' => [
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
                'days' => (int) $start->diffInDays($end),
                'first_transaction_on' => $first->toDateString(),
            ],
            'basis' => [
                'return' => 'cash_flow_adjusted_simple',
                'note' => 'Returns are profit over the capital actually at risk (opening value plus money added), '
                    .'the same convention as performance.periods[].simple_return_percent. Contributions sum to '
                    .'total.return_percent exactly. They are deliberately not a decomposition of XIRR: a '
                    .'money-weighted annualised rate is not additive across holdings, so no honest per-holding '
                    .'split of it exists.',
            ],
            'total' => $attribution['total'],
            'reconciles' => $attribution['reconciles'],
            'reconciliation_error_percent' => $attribution['reconciliation_error_percent'],
            'holdings' => $attribution['holdings'],
            'excluded' => $attribution['excluded'],
            'coverage' => $this->coverage($positions, $attribution),
            'sectors' => $this->sectors($positions, $excludedSymbols, $start, $end),
            'allocation_effect' => $this->benchmarkWeightAvailability(),
        ];
    }

    // ------------------------------------------------------------------
    // Sector selection
    // ------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $positions
     * @param  list<string>  $excludedSymbols
     * @return list<array<string, mixed>>
     */
    private function sectors(array $positions, array $excludedSymbols, Carbon $start, Carbon $end): array
    {
        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];

        foreach ($positions as $position) {
            $slug = $position['sector_slug'];
            $key = $slug ?? 'unclassified';

            $groups[$key] ??= [
                'sector' => $position['sector'] ?? 'Unclassified',
                'sector_slug' => $slug,
                'opening_value' => 0.0,
                'closing_value' => 0.0,
                'net_contribution' => 0.0,
                'holdings' => [],
                'excluded_symbols' => [],
            ];

            if (in_array($position['symbol'], $excludedSymbols, true)) {
                // Named against its sector rather than dropped, so a sector
                // row cannot look complete when part of it is unpriced.
                $groups[$key]['excluded_symbols'][] = $position['symbol'];

                continue;
            }

            $groups[$key]['holdings'][] = $position['symbol'];
            $groups[$key]['opening_value'] += (float) $position['opening_value'];
            $groups[$key]['closing_value'] += (float) $position['closing_value'];
            $groups[$key]['net_contribution'] += (float) $position['net_contribution'];
        }

        $rows = [];

        foreach ($groups as $group) {
            $rows[] = $group + $this->subIndexReturn($group['sector'], $group['sector_slug'], $start, $end);
        }

        return $this->calculator->sectorSelection($rows);
    }

    /**
     * The sub-index leg of one sector comparison, degrading honestly at every
     * step: no classified sector, no sub-index published for that sector, no
     * rows scraped yet, or a window the index history does not reach all
     * report a null return with the reason — never a 0% that reads as "the
     * sector went nowhere".
     *
     * @return array<string, mixed>
     */
    private function subIndexReturn(string $sectorName, ?string $sectorSlug, Carbon $start, Carbon $end): array
    {
        if ($sectorSlug === null) {
            return $this->noSubIndex(null, null, 'These holdings have no classified sector, so no sub-index applies.');
        }

        $slug = $this->indices->subIndexSlugFor($sectorSlug);
        ['series' => $series, 'name' => $indexName] = $this->indices->load($slug, $end);

        if ($series->isEmpty()) {
            return $this->noSubIndex(
                $slug,
                null,
                $this->indices->hasMappedSubIndex($sectorSlug)
                    ? sprintf('No rows scraped yet for the %s sub-index (slug "%s").', $sectorName, $slug)
                    : sprintf(
                        'No sub-index is published for %s (tried slug "%s"). Debentures, government bonds, '
                        .'preference and promoter shares have none, so there is nothing like-for-like to '
                        .'compare against.',
                        $sectorName,
                        $slug,
                    ),
            );
        }

        $period = $this->indexReturns->periodReturn($series, $start, $end);
        $coverage = round($series->coverage($start, $end) * 100, 2);

        if ($period['return_percent'] === null) {
            return $this->noSubIndex(
                $slug,
                $indexName,
                sprintf(
                    '%s history does not span %s to %s (starts %s, runs to %s), so the window is only %s%% '
                    .'covered and the comparison is not computed.',
                    $indexName ?? 'The sub-index',
                    $start->toDateString(),
                    $end->toDateString(),
                    $series->earliestDate()?->toDateString() ?? 'n/a',
                    $series->latestDate()?->toDateString() ?? 'n/a',
                    $coverage,
                ),
                $coverage,
            );
        }

        return [
            'index_slug' => $slug,
            'index_name' => $indexName,
            'index_return_percent' => $period['return_percent'],
            'index_coverage_percent' => $coverage,
            'index_note' => $coverage >= 99.99
                ? null
                : sprintf(
                    '%s covers %s%% of the window; the comparison uses the nearest level on or before each '
                    .'boundary and should be read as provisional.',
                    $indexName ?? 'The sub-index',
                    $coverage,
                ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function noSubIndex(?string $slug, ?string $name, string $note, ?float $coverage = null): array
    {
        return [
            'index_slug' => $slug,
            'index_name' => $name,
            'index_return_percent' => null,
            'index_coverage_percent' => $coverage,
            'index_note' => $note,
        ];
    }

    // ------------------------------------------------------------------
    // Brinson: measured, not assumed
    // ------------------------------------------------------------------

    /**
     * Whether benchmark sector weights can be built, with the numbers behind
     * the verdict rather than an opinion.
     *
     * A Brinson allocation effect needs the *index's* sector weights as at the
     * window start. Market cap is `outstanding_shares × close`, and
     * `stock_fundamentals.outstanding_shares` is well covered — but a share
     * count with no date cannot be applied to a dated window: bonus and rights
     * issues change it constantly, and using today's count with today's close
     * lets the very returns being attributed determine the weights, biasing
     * the allocation effect toward whatever rose. So this reports what it
     * measured and declines to compute the effect.
     *
     * Both figures are measured live rather than hardcoded, so the verdict
     * flips on its own the day `reported_on` starts being populated.
     *
     * @return array<string, mixed>
     */
    public function benchmarkWeightAvailability(): array
    {
        $equities = (int) DB::table('stocks')->where('instrument_type', 'equity')->count();

        $withShares = (int) DB::table('stocks')
            ->where('stocks.instrument_type', 'equity')
            ->whereExists(static fn ($query) => $query->from('stock_fundamentals')
                ->whereColumn('stock_fundamentals.stock_id', 'stocks.id')
                ->whereNotNull('outstanding_shares')
                ->where('outstanding_shares', '>', 0))
            ->count();

        $dated = (int) DB::table('stock_fundamentals')
            ->whereNotNull('outstanding_shares')
            ->where('outstanding_shares', '>', 0)
            ->whereNotNull('reported_on')
            ->count();

        return [
            'available' => false,
            'allocation_effect_percent' => null,
            'selection_effect_percent' => null,
            'outstanding_shares_coverage_percent' => $equities > 0
                ? round($withShares / $equities * 100, 2)
                : null,
            'equities_total' => $equities,
            'equities_with_outstanding_shares' => $withShares,
            'dated_outstanding_shares_rows' => $dated,
            'reason' => $dated > 0
                ? 'Not computed here yet: dated share counts now exist, so benchmark weights as at a window '
                    .'start have become derivable and this can be implemented.'
                : sprintf(
                    'Benchmark sector weights are not derivable. outstanding_shares covers %d of %d equities '
                    .'(%s%%), which is ample, but every one of those rows has a null reported_on and '
                    .'fiscal_period is a non-uniform BS string, so no share count can be dated. Applying '
                    .'undated (effectively current) weights to a past window would let the period\'s own '
                    .'returns set the weights and bias the allocation effect toward whatever rose. Per-sector '
                    .'selection above needs no benchmark weights and is unaffected.',
                    $withShares,
                    $equities,
                    $equities > 0 ? round($withShares / $equities * 100, 2) : 0,
                ),
        ];
    }

    // ------------------------------------------------------------------
    // Plumbing
    // ------------------------------------------------------------------

    /**
     * Money put into each scrip during (from, to] — positive for a buy,
     * negative for a sale or dividend, the inverse of
     * `PerformanceService::cashFlow()` and the same sign convention a period
     * reports `net_contribution` in.
     *
     * Flows dated *on* the window start are excluded: the opening mark is
     * taken at the close of that day, so they are already inside it.
     *
     * @param  Collection<int, PortfolioTransaction>  $transactions
     * @return array<int, float> stock_id => contribution.
     */
    private function contributionsByStock(Collection $transactions, Carbon $start, Carbon $end): array
    {
        $out = [];

        foreach ($transactions as $transaction) {
            $date = $transaction->transacted_on;

            if ($date->lte($start) || $date->gt($end)) {
                continue;
            }

            $amount = PerformanceService::cashFlow($transaction);

            if (abs($amount) < 1e-9) {
                continue;
            }

            $stockId = (int) $transaction->stock_id;
            $out[$stockId] = ($out[$stockId] ?? 0.0) + -$amount;
        }

        return $out;
    }

    /**
     * @param  list<int>  $stockIds
     * @return array<int, array{symbol: string, sector: ?string, sector_slug: ?string}>
     */
    private function stockMeta(array $stockIds): array
    {
        if ($stockIds === []) {
            return [];
        }

        return DB::table('stocks')
            ->leftJoin('sectors', 'sectors.id', '=', 'stocks.sector_id')
            ->whereIn('stocks.id', $stockIds)
            ->get(['stocks.id', 'stocks.symbol', 'sectors.name as sector_name', 'sectors.slug as sector_slug'])
            ->mapWithKeys(static fn (object $row): array => [
                (int) $row->id => [
                    'symbol' => (string) $row->symbol,
                    'sector' => $row->sector_name === null ? null : (string) $row->sector_name,
                    'sector_slug' => $row->sector_slug === null ? null : (string) $row->sector_slug,
                ],
            ])
            ->all();
    }

    /**
     * How much of the book the attribution actually covers, in rupees as well
     * as in count — the same "coverage is not a footnote" rule the index
     * benchmark follows. Excluded value is measured at whichever boundary is
     * known, and reported as null when neither is.
     *
     * @param  list<array<string, mixed>>  $positions
     * @param  array<string, mixed>  $attribution
     * @return array<string, mixed>
     */
    private function coverage(array $positions, array $attribution): array
    {
        $excludedSymbols = array_column($attribution['excluded'], 'symbol');
        $excludedContribution = 0.0;

        foreach ($positions as $position) {
            if (in_array($position['symbol'], $excludedSymbols, true)) {
                $excludedContribution += (float) $position['net_contribution'];
            }
        }

        $base = $attribution['total']['capital_base'];
        $denominator = ($base ?? 0.0) + max(0.0, $excludedContribution);

        return [
            'positions_measured' => $attribution['total']['holdings'],
            'positions_excluded' => $attribution['excluded_count'],
            'excluded_symbols' => $excludedSymbols,
            // Cost of the excluded names, the only figure available for a
            // position that has no mark at all. Never treated as value.
            'excluded_capital_at_cost' => $excludedContribution > 0.0 ? round($excludedContribution, 2) : null,
            'capital_base_covered_percent' => $denominator > 0.0
                ? round(($base ?? 0.0) / $denominator * 100, 2)
                : null,
            'complete' => $attribution['excluded_count'] === 0,
            'note' => $attribution['excluded_count'] === 0
                ? null
                : sprintf(
                    '%d holding(s) had no price or NAV at a window boundary and are excluded, not counted at '
                    .'cost: %s. Counting cost as value would report a 0%% return on them and pull the whole '
                    .'decomposition toward zero.',
                    $attribution['excluded_count'],
                    implode(', ', $excludedSymbols),
                ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function empty(Carbon $end, string $reason): array
    {
        return [
            'window' => [
                'from' => null,
                'to' => $end->toDateString(),
                'days' => 0,
                'first_transaction_on' => null,
            ],
            'basis' => ['return' => 'cash_flow_adjusted_simple', 'note' => $reason],
            'total' => ['capital_base' => null, 'profit' => null, 'return_percent' => null, 'holdings' => 0],
            'reconciles' => true,
            'reconciliation_error_percent' => 0.0,
            'holdings' => [],
            'excluded' => [],
            'coverage' => [
                'positions_measured' => 0,
                'positions_excluded' => 0,
                'excluded_symbols' => [],
                'excluded_capital_at_cost' => null,
                'capital_base_covered_percent' => null,
                'complete' => true,
                'note' => $reason,
            ],
            'sectors' => [],
            'allocation_effect' => $this->benchmarkWeightAvailability(),
        ];
    }

    /**
     * @return Collection<int, PortfolioTransaction>
     */
    private function ledger(Portfolio $portfolio): Collection
    {
        return $portfolio->transactions()
            ->orderBy('transacted_on')
            ->orderBy('id')
            ->get();
    }
}
