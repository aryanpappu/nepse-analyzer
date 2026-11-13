<?php

declare(strict_types=1);

namespace App\Services\Scraper\Backfill;

use App\Models\BackfillProgress;
use App\Models\MarketCalendarDay;
use App\Models\ScrapeRun;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\Scraper\NepseScraperService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Orchestrates the self-healing historical backfill: works out which scrips
 * are short of the configured deep-history target, spends a small, budgeted
 * slice of requests catching a few of them up, and records what it observed
 * (how far back each symbol now reaches, and which calendar dates were
 * actually trading days versus closures) so the next run picks up exactly
 * where this one left off.
 *
 * Deliberately thin on gap/calendar *decisions* - those are pure functions in
 * TradingSessionCalendar, BackfillGapAnalyzer, MarketCalendarClassifier and
 * BackfillBudget, unit tested without a database. This class is the impure
 * glue: it reads config, queries Stock/StockPrice/ScrapeRun/BackfillProgress,
 * and calls the existing NepseScraperService::backfillHistory() to actually
 * fetch and persist bars, rather than duplicating that logic.
 *
 * Nothing here decides whether a date is a trading day from the day of the
 * week - NEPSE's trading week is not a constant (it moved from Sunday-
 * Thursday to Monday-Friday, and public holidays follow the Bikram Sambat
 * calendar, which no formula reproduces). Instead the calendar is *observed*:
 * SharesansarChartSource returns a whole date window in one request per
 * symbol, so the dates that come back in that window are the trading days,
 * and the dates absent from it are non-trading days for the span the window
 * covers - the calendar falls out of the bars already being fetched, at zero
 * additional request cost.
 */
final class HistoryBackfillService
{
    /** @var list<string> Instrument types with meaningful chart history. */
    private const ELIGIBLE_INSTRUMENT_TYPES = ['equity', 'promoter', 'mutual_fund'];

    private readonly TradingSessionCalendar $calendar;

    private readonly BackfillGapAnalyzer $gapAnalyzer;

    private readonly MarketCalendarClassifier $classifier;

    private readonly BackfillBudget $budget;

    private readonly int $targetYears;

    public function __construct(
        private readonly NepseScraperService $scraper,
    ) {
        // Kept only for its allDatesBetween() helper and as a non-authoritative
        // prioritisation hint - see TradingSessionCalendar's class docblock.
        $this->calendar = new TradingSessionCalendar((array) config('nepse.trading_days'));
        $this->gapAnalyzer = new BackfillGapAnalyzer;
        $this->classifier = new MarketCalendarClassifier;
        $this->budget = new BackfillBudget(
            (int) config('nepse.backfill.symbols_per_run'),
            (int) config('nepse.backfill.retry_after_hours'),
        );
        $this->targetYears = max(1, (int) config('nepse.backfill.target_years'));
    }

    /**
     * Run one budgeted slice: touch a few symbols, persist whatever the
     * source gives back, and update everyone's progress.
     *
     * @return array{processed: int, completed: int, exhausted: int, created: int, updated: int}
     */
    public function runDrip(): array
    {
        $this->syncProgressForNewStocks();

        $now = Carbon::now();
        $selected = $this->budget->select($this->eligibleCandidates(), $now);

        if ($selected === []) {
            return ['processed' => 0, 'completed' => 0, 'exhausted' => 0, 'created' => 0, 'updated' => 0];
        }

        $targetStart = $now->copy()->subYears($this->targetYears)->startOfDay();

        // A safe, weekday-agnostic upper bound on how many bars to ask for:
        // every calendar day in the window, not an assumed session count.
        // Asking for more than the source actually has is harmless - it just
        // returns what it has - whereas asking for too few, based on a
        // trading-week assumption that turns out wrong, would silently
        // truncate real history.
        $days = max(30, (int) $targetStart->diffInDays($now));

        $stockIds = array_map(static fn (BackfillCandidate $c): int => $c->stockId, $selected);
        $symbols = array_map(static fn (BackfillCandidate $c): string => $c->symbol, $selected);

        $before = $this->earliestBarDates($stockIds);

        // $targetStart lets the chart source stop paging once it has reached the
        // target, instead of walking on through years beyond it.
        $run = $this->scraper->backfillHistory($symbols, $days, count($symbols), since: $targetStart);

        $after = $this->earliestBarDates($stockIds);

        $completed = 0;
        $exhausted = 0;

        foreach ($selected as $candidate) {
            [$status, $result] = $this->resolveOutcome(
                $before[$candidate->stockId] ?? null,
                $after[$candidate->stockId] ?? null,
                $targetStart,
            );

            match ($status) {
                BackfillProgress::STATUS_COMPLETE => $completed++,
                BackfillProgress::STATUS_EXHAUSTED => $exhausted++,
                default => null,
            };

            BackfillProgress::where('stock_id', $candidate->stockId)->update([
                'earliest_bar_date' => $after[$candidate->stockId] ?? null,
                'status' => $status,
                'attempts' => DB::raw('attempts + 1'),
                'last_attempted_at' => $now,
                'last_result' => $result,
            ]);
        }

        $this->observeCalendar($targetStart, $now);

        return [
            'processed' => count($selected),
            'completed' => $completed,
            'exhausted' => $exhausted,
            'created' => (int) $run->rows_created,
            'updated' => (int) $run->rows_updated,
        ];
    }

    /**
     * @return array{rows: Collection<int, object>, counts: Collection<string, int>, estimated_runs_to_clear: int, calendar_counts: Collection<string, int>}
     */
    public function statusSummary(?string $symbol = null): array
    {
        $query = BackfillProgress::query()
            ->join('stocks', 'stocks.id', '=', 'backfill_progress.stock_id')
            ->select('stocks.symbol as symbol', 'backfill_progress.*');

        if ($symbol !== null) {
            $query->where('stocks.symbol', strtoupper(trim($symbol)));
        }

        $rows = $query->orderBy('stocks.symbol')->get();

        $counts = $rows->countBy(static fn (object $row): string => (string) $row->status);

        $pending = ($counts[BackfillProgress::STATUS_PENDING] ?? 0) + ($counts[BackfillProgress::STATUS_ACTIVE] ?? 0);

        $calendarCounts = MarketCalendarDay::query()
            ->selectRaw('classification, COUNT(*) as total')
            ->groupBy('classification')
            ->pluck('total', 'classification');

        return [
            'rows' => $rows,
            'counts' => $counts,
            'estimated_runs_to_clear' => $this->budget->estimatedRunsToClear($pending),
            'calendar_counts' => $calendarCounts,
        ];
    }

    /**
     * Reset progress back to pending so it is picked up again - for a manual
     * `--reset`, e.g. after the source starts serving deeper history again.
     */
    public function reset(?string $symbol = null): int
    {
        $query = BackfillProgress::query();

        if ($symbol !== null) {
            $query->whereHas('stock', static fn ($q) => $q->where('symbol', strtoupper(trim($symbol))));
        }

        return $query->update([
            'status' => BackfillProgress::STATUS_PENDING,
            'attempts' => 0,
            'earliest_bar_date' => null,
            'last_attempted_at' => null,
            'last_result' => null,
        ]);
    }

    /**
     * Manually assert a calendar date's classification - the correction path
     * for a wrong automatic verdict (or a known festival closure the
     * evidence has not caught up with yet). Always wins over inference, and
     * is itself always revisable by another manual call.
     */
    public function markManualCalendarDay(string $date, string $classification): void
    {
        if (! in_array($classification, [MarketCalendarDay::TRADING_DAY, MarketCalendarDay::HOLIDAY], true)) {
            throw new InvalidArgumentException("Unknown classification '{$classification}'.");
        }

        $tradeDate = Carbon::parse($date)->toDateString();

        MarketCalendarDay::query()->updateOrCreate(
            ['trade_date' => $tradeDate],
            [
                'classification' => $classification,
                'classified_by' => MarketCalendarDay::CLASSIFIED_BY_MANUAL,
                'sync_status' => MarketCalendarDay::SYNC_ATTEMPTED,
                'notes' => 'set manually via nepse:backfill-drip',
            ],
        );
    }

    /**
     * Create a pending progress row for every eligible stock that does not
     * have one yet - a symbol added later gets the same deep-history target
     * as everyone else, without an operator remembering to seed it.
     */
    private function syncProgressForNewStocks(): void
    {
        $targetStart = Carbon::now()->subYears($this->targetYears)->startOfDay()->toDateString();
        $existingIds = BackfillProgress::query()->pluck('stock_id');

        Stock::query()
            ->active()
            ->whereIn('instrument_type', self::ELIGIBLE_INSTRUMENT_TYPES)
            ->whereNotIn('id', $existingIds)
            ->select('id')
            ->chunkById(200, function (Collection $stocks) use ($targetStart): void {
                $now = Carbon::now();

                $rows = $stocks->map(static fn (Stock $stock): array => [
                    'stock_id' => $stock->id,
                    'target_start_date' => $targetStart,
                    'status' => BackfillProgress::STATUS_PENDING,
                    'attempts' => 0,
                    'bars_fetched' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if ($rows !== []) {
                    BackfillProgress::query()->insertOrIgnore($rows);
                }
            });
    }

    /**
     * @return list<BackfillCandidate>
     */
    private function eligibleCandidates(): array
    {
        return BackfillProgress::query()
            ->join('stocks', 'stocks.id', '=', 'backfill_progress.stock_id')
            ->where('stocks.is_active', true)
            ->whereIn('backfill_progress.status', [BackfillProgress::STATUS_PENDING, BackfillProgress::STATUS_ACTIVE])
            ->select(
                'backfill_progress.stock_id as stock_id',
                'stocks.symbol as symbol',
                'backfill_progress.status as status',
                'backfill_progress.last_attempted_at as last_attempted_at',
            )
            ->get()
            ->map(static fn (object $row): BackfillCandidate => new BackfillCandidate(
                stockId: (int) $row->stock_id,
                symbol: (string) $row->symbol,
                status: (string) $row->status,
                lastAttemptedAt: $row->last_attempted_at !== null ? Carbon::parse($row->last_attempted_at) : null,
            ))
            ->all();
    }

    /**
     * @param  list<int>  $stockIds
     * @return array<int, string> stock_id => earliest trade_date (Y-m-d)
     */
    private function earliestBarDates(array $stockIds): array
    {
        if ($stockIds === []) {
            return [];
        }

        return StockPrice::query()
            ->whereIn('stock_id', $stockIds)
            ->selectRaw('stock_id, MIN(trade_date) as earliest')
            ->groupBy('stock_id')
            ->pluck('earliest', 'stock_id')
            ->map(static fn (mixed $date): string => Carbon::parse((string) $date)->toDateString())
            ->all();
    }

    /**
     * @return array{0: string, 1: string} [status, human-readable reason]
     */
    private function resolveOutcome(?string $beforeDate, ?string $afterDate, Carbon $targetStart): array
    {
        $target = $targetStart->toDateString();

        if ($afterDate !== null && $afterDate <= $target) {
            return [BackfillProgress::STATUS_COMPLETE, "reached the {$this->targetYears}-year target ({$target})"];
        }

        if ($afterDate === null) {
            // Never produced a single bar - a brand-new listing, or a scrip the
            // source has nothing for. Not worth retrying every run.
            return [BackfillProgress::STATUS_EXHAUSTED, 'source returned no history at all'];
        }

        if ($beforeDate !== null && $afterDate >= $beforeDate) {
            // Asked for the full target window and got nothing further back
            // than we already had - this is as deep as the source goes.
            return [BackfillProgress::STATUS_EXHAUSTED, "source has no data before {$afterDate}"];
        }

        return [BackfillProgress::STATUS_ACTIVE, "reached back to {$afterDate}, short of target {$target}"];
    }

    /**
     * Observe what the market data now shows for every date in the window and
     * (re-)classify the ones not already settled.
     *
     * A date is a trading day the moment any symbol, anywhere, has a bar for
     * it - no weekday reasoning involved. A date is only confirmed a holiday
     * once we actually held enough evidence to trust its silence: either the
     * live daily pipeline successfully scraped that exact date and found
     * nothing (App\Models\ScrapeRun, type daily_prices), or the liquid
     * reference basket's own fetch coverage has reached back across it (see
     * BackfillGapAnalyzer::wellCoveredSpan()). Anything else stays `unknown`
     * and eligible - never permanently written off.
     *
     * Confirmed trading days and manual overrides are final and never
     * revisited. Automatic holiday verdicts stay open to correction by new
     * bar evidence (a later backfill discovering a bar there), which is
     * exactly the "revisable" property a wrong verdict needs.
     */
    private function observeCalendar(Carbon $from, Carbon $to): void
    {
        $dates = $this->calendar->allDatesBetween($from, $to);

        if ($dates === []) {
            return;
        }

        $settled = MarketCalendarDay::query()
            ->whereBetween('trade_date', [$from->toDateString(), $to->toDateString()])
            ->where(function ($query): void {
                $query->where('classification', MarketCalendarDay::TRADING_DAY)
                    ->orWhere('classified_by', MarketCalendarDay::CLASSIFIED_BY_MANUAL);
            })
            ->pluck('trade_date')
            ->map(static fn (mixed $date): string => Carbon::parse((string) $date)->toDateString())
            ->all();

        $candidates = array_values(array_diff($dates, $settled));

        if ($candidates === []) {
            return;
        }

        $wholeMarketCoverage = $this->coverageByDate($from, $to, null);

        $basketStockIds = $this->resolveHolidayBasketStockIds();
        $basketCoverage = $basketStockIds === [] ? [] : $this->coverageByDate($from, $to, $basketStockIds);
        $basketSpan = $this->gapAnalyzer->wellCoveredSpan(
            $basketCoverage,
            count($basketStockIds),
            (float) config('nepse.backfill.basket_coverage_fraction'),
        );

        $checkedByDailyRun = array_flip(
            ScrapeRun::query()
                ->where('type', 'daily_prices')
                ->whereIn('status', ['success', 'partial'])
                ->whereBetween('trade_date', [$from->toDateString(), $to->toDateString()])
                ->pluck('trade_date')
                ->map(static fn (mixed $date): string => Carbon::parse((string) $date)->toDateString())
                ->all(),
        );

        $existingAttempts = MarketCalendarDay::query()
            ->whereIn('trade_date', $candidates)
            ->get(['trade_date', 'attempts'])
            ->mapWithKeys(static fn (MarketCalendarDay $row): array => [
                Carbon::parse((string) $row->trade_date)->toDateString() => (int) $row->attempts,
            ]);

        $now = Carbon::now();
        $rows = [];

        foreach ($candidates as $date) {
            $hasObservedBar = ($wholeMarketCoverage[$date] ?? 0) > 0;
            $wasChecked = $this->gapAnalyzer->withinSpan($date, $basketSpan) || isset($checkedByDailyRun[$date]);

            $result = $this->classifier->classify($hasObservedBar, $wasChecked);

            // Nothing learned - do not write a placeholder row for every
            // one of up to ~1800 unresolved dates every single run.
            if ($result['classification'] === MarketCalendarDay::UNKNOWN) {
                continue;
            }

            $rows[] = [
                'trade_date' => $date,
                'sync_status' => $hasObservedBar ? MarketCalendarDay::SYNC_DATA_FOUND : MarketCalendarDay::SYNC_NO_DATA,
                'attempts' => ($existingAttempts[$date] ?? 0) + 1,
                'last_attempted_at' => $now,
                'observed_symbol_count' => $wholeMarketCoverage[$date] ?? 0,
                'classification' => $result['classification'],
                'classified_by' => $result['classified_by'],
                'notes' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, 250) as $chunk) {
            MarketCalendarDay::query()->upsert(
                $chunk,
                ['trade_date'],
                ['sync_status', 'attempts', 'last_attempted_at', 'observed_symbol_count', 'classification', 'classified_by', 'notes', 'updated_at'],
            );
        }
    }

    /**
     * @param  list<int>|null  $stockIds  Null = whole market.
     * @return array<string, int> Y-m-d date => distinct symbols with a bar that day.
     */
    private function coverageByDate(Carbon $from, Carbon $to, ?array $stockIds): array
    {
        $query = StockPrice::query()
            ->whereBetween('trade_date', [$from->toDateString(), $to->toDateString()]);

        if ($stockIds !== null) {
            $query->whereIn('stock_id', $stockIds);
        }

        $coverage = [];

        foreach (
            $query
                ->selectRaw('trade_date, COUNT(DISTINCT stock_id) as symbol_count')
                ->groupBy('trade_date')
                ->pluck('symbol_count', 'trade_date') as $date => $count
        ) {
            $coverage[Carbon::parse((string) $date)->toDateString()] = (int) $count;
        }

        return $coverage;
    }

    /**
     * The liquid reference basket used to confirm holidays: an explicit
     * config list if one is set, otherwise the N stocks (holiday_basket_size)
     * with the most stored daily bars - a liquidity/completeness proxy that
     * needs no turnover figures, which are not reliably available for
     * backfilled bars.
     *
     * @return list<int> stock IDs
     */
    private function resolveHolidayBasketStockIds(): array
    {
        $explicitSymbols = (array) config('nepse.backfill.holiday_basket_symbols');

        if ($explicitSymbols !== []) {
            return Stock::query()
                ->whereIn('symbol', array_map(static fn (string $symbol): string => strtoupper(trim($symbol)), $explicitSymbols))
                ->pluck('id')
                ->all();
        }

        $size = max(1, (int) config('nepse.backfill.holiday_basket_size'));

        return Stock::query()
            ->active()
            ->whereIn('instrument_type', self::ELIGIBLE_INSTRUMENT_TYPES)
            ->withCount('prices')
            ->orderByDesc('prices_count')
            ->limit($size)
            ->pluck('id')
            ->all();
    }
}
