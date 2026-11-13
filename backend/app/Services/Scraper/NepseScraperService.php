<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Exceptions\ScrapeException;
use App\Models\MarketIndex;
use App\Models\ScrapeRun;
use App\Models\Sector;
use App\Models\Stock;
use App\Models\StockFundamental;
use App\Models\StockPrice;
use App\Services\Scraper\Contracts\FundamentalsSource;
use App\Services\Scraper\Contracts\HistorySource;
use App\Services\Scraper\Contracts\PriceSource;
use App\Services\Scraper\Sources\SharesansarIndexHistorySource;
use App\Services\Scraper\Sources\SharesansarSource;
use App\Support\Numeric;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates a scrape: pick a source, parse, validate, persist, record the run.
 *
 * Everything here is written to be safely re-runnable — the same trading day
 * can be scraped repeatedly (after a failure, or to pick up a late correction)
 * without creating duplicate history.
 */
final class NepseScraperService
{
    public function __construct(
        private readonly PriceSourceManager $sources,
        private readonly PriceRowValidator $validator,
        private readonly HistorySource $history,
        private readonly IndexRowValidator $indexValidator,
        private readonly SharesansarIndexHistorySource $indexHistory,
        private readonly HttpFetcher $http,
        private readonly FiscalPeriodDater $fiscalPeriods = new FiscalPeriodDater,
    ) {}

    /**
     * Newest session we hold prices for, memoised for the life of a run.
     *
     * Used as the reference for "has this reporting period ended yet" when
     * dating fundamentals. Deliberately an observed date rather than
     * Carbon::now(): the whole scraper is built on the rule that a date comes
     * off the data and never off the local clock, and a fiscal period the
     * source mislabels as a future quarter must be left undated rather than
     * given a date that sorts ahead of every real filing.
     */
    private ?Carbon $observedThrough = null;

    private bool $observedThroughResolved = false;

    /**
     * Scrape and persist the daily price table.
     *
     * @param  string|null  $onlySource  Force a specific source instead of the
     *                                   configured failover chain.
     */
    public function scrapeDailyPrices(?Carbon $date = null, ?string $onlySource = null): ScrapeRun
    {
        $tradeDate = ($date ?? $this->latestTradingDay())->startOfDay();

        $run = ScrapeRun::create([
            'type' => 'daily_prices',
            'trade_date' => $tradeDate->toDateString(),
            'status' => ScrapeRun::STATUS_RUNNING,
            'started_at' => Carbon::now(),
        ]);

        $startedAt = microtime(true);
        $sources = $onlySource !== null
            ? [$this->sources->make($onlySource)]
            : $this->sources->enabled();

        $lastError = null;

        foreach ($sources as $source) {
            try {
                $snapshot = $source->fetchDailyPrices($date);
                $rows = $snapshot->rows;

                $minRows = (int) config('nepse.validation.min_rows');

                if (count($rows) < $minRows) {
                    throw ScrapeException::tooFewRows($source->key(), count($rows), $minRows);
                }

                // The page is the authority on which session these prices are.
                // If the caller asked for a specific day and the source served a
                // different one, writing it would silently corrupt history — so
                // refuse instead.
                if ($date !== null && ! $snapshot->tradeDate->isSameDay($date)) {
                    throw new ScrapeException(
                        sprintf(
                            '[%s] cannot provide %s — it served the %s session instead. '
                            .'This source only publishes the latest session, so historical backfill is not possible from it.',
                            $source->key(),
                            $date->toDateString(),
                            $snapshot->tradeDate->toDateString(),
                        ),
                        ['requested' => $date->toDateString(), 'served' => $snapshot->tradeDate->toDateString()],
                    );
                }

                $tradeDate = $snapshot->tradeDate->copy()->startOfDay();

                $result = $this->persistPrices($rows, $tradeDate, $source);

                // Indices are supplementary to the price scrape, not a
                // precondition of it: a source that cannot serve them (every
                // PriceSource but SharesansarSource, today) or a transient
                // failure on the extra index request must never fail a run
                // whose real job — the price table — already succeeded. Pinned
                // to this run's own source rather than failing over, since a
                // source that already served prices successfully is the right
                // one to ask for the same session's indices.
                try {
                    $this->scrapeIndices($tradeDate, $source->key());
                } catch (Throwable $e) {
                    Log::channel('scraper')->warning('Index capture failed; prices are unaffected', [
                        'source' => $source->key(),
                        'trade_date' => $tradeDate->toDateString(),
                        'error' => $e->getMessage(),
                    ]);
                }

                $run->update([
                    'source' => $source->key(),
                    'trade_date' => $tradeDate->toDateString(),
                    'status' => $result['rejected'] > 0 ? ScrapeRun::STATUS_PARTIAL : ScrapeRun::STATUS_SUCCESS,
                    'rows_found' => count($rows),
                    'rows_created' => $result['created'],
                    'rows_updated' => $result['updated'],
                    'rows_rejected' => $result['rejected'],
                    'rejections' => $result['rejections'],
                    'finished_at' => Carbon::now(),
                    'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                ]);

                Log::channel('scraper')->info('Daily price scrape finished', [
                    'source' => $source->key(),
                    'trade_date' => $tradeDate->toDateString(),
                    'found' => count($rows),
                    'created' => $result['created'],
                    'updated' => $result['updated'],
                    'rejected' => $result['rejected'],
                ]);

                return $run->refresh();
            } catch (ScrapeException $e) {
                $lastError = $e;

                Log::channel('scraper')->warning('Source failed, trying next', [
                    'source' => $source->key(),
                    'error' => $e->getMessage(),
                    'context' => $e->context,
                ]);
            } catch (Throwable $e) {
                $lastError = $e;

                Log::channel('scraper')->error('Unexpected scrape failure', [
                    'source' => $source->key(),
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
            }
        }

        $run->update([
            'status' => ScrapeRun::STATUS_FAILED,
            'error' => $lastError?->getMessage() ?? 'all sources failed',
            'finished_at' => Carbon::now(),
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        throw new ScrapeException(
            'Every configured NEPSE source failed for '.$tradeDate->toDateString().': '
                .($lastError?->getMessage() ?? 'unknown error'),
            ['trade_date' => $tradeDate->toDateString()],
            $lastError,
        );
    }

    /**
     * @param  list<PriceRow>  $rows
     * @return array{created: int, updated: int, rejected: int, rejections: list<array<string, mixed>>}
     */
    private function persistPrices(array $rows, Carbon $tradeDate, PriceSource $source): array
    {
        // Resolve every symbol up front — one query instead of one per row.
        $symbols = array_map(static fn (PriceRow $row): string => $row->symbol, $rows);
        $stocks = Stock::whereIn('symbol', $symbols)->get()->keyBy('symbol');

        // Previous closes for the validator, so a 10x price jump is caught even
        // when the source omits its own previous-close column.
        // The date comes along so the validator can tell a genuine previous
        // session from a close left behind by a scrape we missed.
        // Only the latest earlier bar per stock is fetched: loading every
        // historical close and de-duplicating in PHP hydrated the whole price
        // table (300k+ rows) and exhausted a 512M worker on every run.
        $latestEarlier = DB::table('stock_prices')
            ->select('stock_id', DB::raw('MAX(trade_date) as trade_date'))
            ->whereIn('stock_id', $stocks->pluck('id'))
            ->where('trade_date', '<', $tradeDate->toDateString())
            ->groupBy('stock_id');

        $previousCloses = DB::table('stock_prices as p')
            ->joinSub($latestEarlier, 'l', static function ($join): void {
                $join->on('p.stock_id', '=', 'l.stock_id')
                    ->on('p.trade_date', '=', 'l.trade_date');
            })
            ->get(['p.stock_id', 'p.close', 'p.trade_date'])
            ->keyBy('stock_id');

        $created = 0;
        $updated = 0;
        $rejected = 0;
        $repaired = 0;
        $rejections = [];
        $priceRows = [];
        $stockUpdates = [];
        $now = Carbon::now();

        foreach ($rows as $row) {
            // Salvage rows whose intraday bar contradicts itself before the
            // validator sees them; the close is still usable.
            $repair = $this->validator->repair($row);

            if ($repair['repaired']) {
                $repaired++;
                $row = $repair['row'];
            }

            $stock = $stocks->get($row->symbol);

            if ($stock === null) {
                // First time we have seen this ticker — create a stub. Name and
                // sector are backfilled by the fundamentals crawler.
                $stock = Stock::create([
                    'symbol' => $row->symbol,
                    'name' => $row->name ?? $row->symbol,
                    'instrument_type' => $this->guessInstrumentType($row->symbol),
                    'is_active' => true,
                ]);

                $stocks->put($row->symbol, $stock);
            }

            $storedPrevious = $previousCloses->get($stock->id);
            $previousClose = $storedPrevious === null ? null : (float) $storedPrevious->close;
            $previousCloseAgeDays = $storedPrevious === null
                ? null
                : (int) Carbon::parse($storedPrevious->trade_date)->startOfDay()->diffInDays($tradeDate);

            $verdict = $this->validator->validate($row, $previousClose, $previousCloseAgeDays);

            if (! $verdict['valid']) {
                $rejected++;

                // Cap what we store; a systemic parse break would otherwise
                // write thousands of near-identical rejection records.
                if (count($rejections) < 25) {
                    $rejections[] = [
                        'symbol' => $row->symbol,
                        'reason' => $verdict['reason'] ?? 'unknown',
                        'row' => $row->toArray(),
                    ];
                }

                continue;
            }

            $priceRows[] = [
                'stock_id' => $stock->id,
                'trade_date' => $tradeDate->toDateString(),
                'open' => $row->open,
                'high' => $row->high,
                'low' => $row->low,
                'close' => $row->close,
                'previous_close' => $row->previousClose ?? $previousClose,
                'average_price' => $row->averagePrice,
                'change_amount' => $row->changeAmount()
                    ?? ($previousClose === null ? null : round($row->close - $previousClose, 2)),
                'change_percent' => $row->resolvedChangePercent()
                    ?? ($previousClose === null ? null : round(($row->close - $previousClose) / $previousClose * 100, 2)),
                'volume' => $row->volume,
                'turnover' => $row->turnover,
                'transactions' => $row->transactions,
                'fifty_two_week_high' => $row->fiftyTwoWeekHigh,
                'fifty_two_week_low' => $row->fiftyTwoWeekLow,
                'source' => $source->key(),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $stockUpdates[$stock->id] = [
                'last_price' => $row->close,
                'previous_close' => $row->previousClose ?? $previousClose,
                'change_percent' => $row->resolvedChangePercent(),
                'last_traded_on' => $tradeDate->toDateString(),
            ];
        }

        if ($repaired > 0) {
            Log::channel('scraper')->info('Dropped inconsistent intraday bars, kept the closes', [
                'source' => $source->key(),
                'count' => $repaired,
            ]);
        }

        if ($priceRows === []) {
            return ['created' => 0, 'updated' => 0, 'rejected' => $rejected, 'rejections' => $rejections];
        }

        // Count what already exists so the run report distinguishes a fresh
        // scrape from a re-run over the same day.
        $existing = StockPrice::where('trade_date', $tradeDate->toDateString())
            ->whereIn('stock_id', array_column($priceRows, 'stock_id'))
            ->pluck('stock_id')
            ->flip();

        foreach ($priceRows as $priceRow) {
            $existing->has($priceRow['stock_id']) ? $updated++ : $created++;
        }

        DB::transaction(function () use ($priceRows, $stockUpdates): void {
            foreach (array_chunk($priceRows, 250) as $chunk) {
                StockPrice::upsert(
                    $chunk,
                    ['stock_id', 'trade_date'],
                    [
                        'open', 'high', 'low', 'close', 'previous_close', 'average_price',
                        'change_amount', 'change_percent', 'volume', 'turnover',
                        'transactions', 'fifty_two_week_high', 'fifty_two_week_low',
                        'source', 'updated_at',
                    ],
                );
            }

            foreach ($stockUpdates as $stockId => $attributes) {
                Stock::whereKey($stockId)->update($attributes);
            }
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'rejected' => $rejected,
            'rejections' => $rejections,
        ];
    }

    /**
     * Scrape and persist the current session's indices (NEPSE Index,
     * Sensitive Index, sector sub-indices), in their own {@see ScrapeRun}.
     *
     * Mirrors scrapeDailyPrices()'s failover shape, but stands on its own —
     * called both directly (`nepse:indices`) and internally, pinned to the
     * source that already served this run's prices, from scrapeDailyPrices().
     *
     * @param  string|null  $onlySource  Force a specific source instead of the
     *                                   configured failover chain.
     *
     * @throws ScrapeException When every source fails to serve indices.
     */
    public function scrapeIndices(?Carbon $date = null, ?string $onlySource = null): ScrapeRun
    {
        $run = ScrapeRun::create([
            'type' => 'market_indices',
            'trade_date' => $date?->toDateString(),
            'status' => ScrapeRun::STATUS_RUNNING,
            'started_at' => Carbon::now(),
        ]);

        $startedAt = microtime(true);
        $sources = $onlySource !== null
            ? [$this->sources->make($onlySource)]
            : $this->sources->enabled();

        $lastError = null;

        foreach ($sources as $source) {
            try {
                $snapshot = $source->fetchIndices($date);

                if ($snapshot->rows === []) {
                    // Not every source can serve indices at all — that is
                    // normal (see AbstractHtmlSource's default) — but it still
                    // means this source contributed nothing, so move on to
                    // whichever one does rather than recording a false success.
                    throw ScrapeException::tooFewRows($source->key(), 0, 1);
                }

                $tradeDate = ($snapshot->tradeDate ?? $date ?? $this->latestTradingDay())->copy()->startOfDay();

                $result = $this->persistIndexRows($snapshot->rows, $tradeDate, $source->key());

                $run->update([
                    'source' => $source->key(),
                    'trade_date' => $tradeDate->toDateString(),
                    'status' => $result['rejected'] > 0 ? ScrapeRun::STATUS_PARTIAL : ScrapeRun::STATUS_SUCCESS,
                    'rows_found' => count($snapshot->rows),
                    'rows_created' => $result['created'],
                    'rows_updated' => $result['updated'],
                    'rows_rejected' => $result['rejected'],
                    'rejections' => $result['rejections'],
                    'finished_at' => Carbon::now(),
                    'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                ]);

                Log::channel('scraper')->info('Index scrape finished', [
                    'source' => $source->key(),
                    'trade_date' => $tradeDate->toDateString(),
                    'created' => $result['created'],
                    'updated' => $result['updated'],
                    'rejected' => $result['rejected'],
                ]);

                return $run->refresh();
            } catch (ScrapeException $e) {
                $lastError = $e;

                Log::channel('scraper')->warning('Index source failed, trying next', [
                    'source' => $source->key(),
                    'error' => $e->getMessage(),
                ]);
            } catch (Throwable $e) {
                $lastError = $e;

                Log::channel('scraper')->error('Unexpected index scrape failure', [
                    'source' => $source->key(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $run->update([
            'status' => ScrapeRun::STATUS_FAILED,
            'error' => $lastError?->getMessage() ?? 'all sources failed',
            'finished_at' => Carbon::now(),
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        throw new ScrapeException(
            'Every configured NEPSE source failed to serve indices'.($date !== null ? ' for '.$date->toDateString() : '')
                .': '.($lastError?->getMessage() ?? 'unknown error'),
            ['trade_date' => $date?->toDateString()],
            $lastError,
        );
    }

    /**
     * @param  list<IndexRow>  $rows
     * @return array{created: int, updated: int, rejected: int, rejections: list<array<string, mixed>>}
     */
    private function persistIndexRows(array $rows, Carbon $tradeDate, string $sourceKey): array
    {
        $slugs = array_map(static fn (IndexRow $row): string => $row->slug(), $rows);

        // Same shape as persistPrices()'s $previousCloses: the most recent
        // stored value per index strictly before this session, so a day-on-day
        // move can be judged even though the live widget publishes no
        // previous value of its own — only a percentage.
        $previous = MarketIndex::query()
            ->whereIn('slug', $slugs)
            ->where('trade_date', '<', $tradeDate->toDateString())
            ->orderByDesc('trade_date')
            ->get(['slug', 'value', 'trade_date'])
            ->unique('slug')
            ->keyBy('slug');

        $created = 0;
        $updated = 0;
        $rejected = 0;
        $rejections = [];
        $indexRows = [];
        $now = Carbon::now();

        foreach ($rows as $row) {
            $slug = $row->slug();
            $stored = $previous->get($slug);
            $previousValue = $stored === null ? null : (float) $stored->value;
            $previousValueAgeDays = $stored === null
                ? null
                : (int) Carbon::parse($stored->trade_date)->startOfDay()->diffInDays($tradeDate);

            $verdict = $this->indexValidator->validate(
                $row->name,
                $row->value,
                $row->changePercent,
                $previousValue,
                $previousValueAgeDays,
            );

            if (! $verdict['valid']) {
                $rejected++;

                if (count($rejections) < 25) {
                    $rejections[] = ['name' => $row->name, 'reason' => $verdict['reason'] ?? 'unknown'];
                }

                continue;
            }

            $indexRows[] = [
                'name' => $row->name,
                'slug' => $slug,
                'trade_date' => $tradeDate->toDateString(),
                'value' => $row->value,
                // Arithmetic we control wins over the scraped percentage,
                // same rule PriceRow::resolvedChangePercent() applies —
                // there is no scraped change_amount from this widget at all,
                // so it can only ever come from the stored previous value.
                'change_amount' => $previousValue === null ? null : round($row->value - $previousValue, 4),
                'change_percent' => $previousValue !== null && $previousValue > 0
                    ? round(($row->value - $previousValue) / $previousValue * 100, 4)
                    : $row->changePercent,
                'total_turnover' => $row->totalTurnover,
                'total_volume' => $row->totalVolume,
                'total_transactions' => $row->totalTransactions,
                'advances' => $row->advances,
                'declines' => $row->declines,
                'unchanged' => $row->unchanged,
                'source' => $sourceKey,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($indexRows === []) {
            return ['created' => 0, 'updated' => 0, 'rejected' => $rejected, 'rejections' => $rejections];
        }

        $existing = MarketIndex::where('trade_date', $tradeDate->toDateString())
            ->whereIn('slug', array_column($indexRows, 'slug'))
            ->pluck('slug')
            ->flip();

        foreach ($indexRows as $indexRow) {
            $existing->has($indexRow['slug']) ? $updated++ : $created++;
        }

        // Same upsert key as prices — (slug, trade_date) — so the 15:00 close
        // supersedes the 12:00 midday reading instead of duplicating it.
        MarketIndex::upsert(
            $indexRows,
            ['slug', 'trade_date'],
            [
                'name', 'value', 'change_amount', 'change_percent', 'total_turnover',
                'total_volume', 'total_transactions', 'advances', 'declines', 'unchanged',
                'source', 'updated_at',
            ],
        );

        return ['created' => $created, 'updated' => $updated, 'rejected' => $rejected, 'rejections' => $rejections];
    }

    /**
     * Backfill historical daily index bars from ShareSansar's index-history
     * feed ({@see SharesansarIndexHistorySource}).
     *
     * @param  list<string>|null  $indexNames  Null backfills every known index.
     * @param  int  $days  Calendar days to request; 0 means "since inception".
     * @param  callable|null  $onProgress  fn(string $indexName, int $written): void
     */
    public function backfillIndexHistory(
        ?array $indexNames = null,
        int $days = 1825,
        ?callable $onProgress = null,
    ): ScrapeRun {
        $run = ScrapeRun::create([
            'type' => 'market_index_backfill',
            'source' => $this->indexHistory->key(),
            'status' => ScrapeRun::STATUS_RUNNING,
            'started_at' => Carbon::now(),
        ]);

        $startedAt = microtime(true);

        $names = $indexNames === null || $indexNames === []
            ? $this->indexHistory->availableIndices()
            : array_values(array_unique(array_filter(array_map(
                static fn (string $name): ?string => IndexCatalog::canonicalize($name),
                $indexNames,
            ))));

        $to = Carbon::now(config('nepse.timezone'))->endOfDay();
        $from = $days > 0
            ? $to->copy()->subDays($days)->startOfDay()
            // "Since inception": well before ShareSansar's earliest published
            // index bar (NEPSE Index itself starts 1997-07-20).
            : Carbon::parse('1990-01-01')->startOfDay();

        $created = 0;
        $updated = 0;
        $empty = 0;
        $failures = [];

        foreach ($names as $name) {
            try {
                $bars = $this->indexHistory->fetchIndexHistory($name, $from, $to);
            } catch (Throwable $e) {
                Log::channel('scraper')->warning('Index history backfill failed', [
                    'index' => $name,
                    'error' => $e->getMessage(),
                ]);

                if (count($failures) < 25) {
                    $failures[] = ['index' => $name, 'reason' => $e->getMessage()];
                }

                continue;
            }

            if ($bars === []) {
                $empty++;

                if ($onProgress !== null) {
                    $onProgress($name, 0);
                }

                continue;
            }

            [$indexCreated, $indexUpdated] = $this->persistIndexHistory($name, $bars);
            $created += $indexCreated;
            $updated += $indexUpdated;

            if ($onProgress !== null) {
                $onProgress($name, count($bars));
            }
        }

        $run->update([
            'status' => $failures === [] ? ScrapeRun::STATUS_SUCCESS : ScrapeRun::STATUS_PARTIAL,
            'rows_found' => count($names),
            'rows_created' => $created,
            'rows_updated' => $updated,
            'rows_rejected' => $empty,
            'rejections' => $failures,
            'finished_at' => Carbon::now(),
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        return $run->refresh();
    }

    /**
     * @param  list<IndexHistoryBar>  $bars  Oldest first.
     * @return array{0: int, 1: int} [created, updated]
     */
    private function persistIndexHistory(string $indexName, array $bars): array
    {
        $slug = $bars[0]->slug();

        // Seed from the session immediately before the window, exactly like
        // backfillHistory() seeds $previousClose, so the oldest bar in the
        // window still gets a real day-on-day comparison instead of a blank
        // one every time this runs.
        $seed = MarketIndex::where('slug', $slug)
            ->where('trade_date', '<', $bars[0]->date->toDateString())
            ->orderByDesc('trade_date')
            ->first(['value', 'trade_date']);

        $previousValue = $seed === null ? null : (float) $seed->value;
        $previousDate = $seed === null ? null : Carbon::parse($seed->trade_date)->startOfDay();

        $existing = MarketIndex::where('slug', $slug)
            ->pluck('trade_date')
            ->map(static fn ($date): string => Carbon::parse($date)->toDateString())
            ->flip();

        $rows = [];
        $now = Carbon::now();
        $created = 0;
        $updated = 0;

        foreach ($bars as $bar) {
            $repair = $this->indexValidator->repair($bar);
            $bar = $repair['bar'];

            // Carbon 3 returns a float here; the validator wants whole days.
            $ageDays = $previousDate === null ? null : (int) $previousDate->diffInDays($bar->date);

            $verdict = $this->indexValidator->validate(
                $bar->name,
                $bar->value,
                $bar->changePercent,
                $previousValue,
                $ageDays,
            );

            // Advance the chain regardless of the verdict — chaining off the
            // batch's own previous bar, not a fresh DB query each time, is
            // exactly what backfillHistory() does for prices. Skipping a
            // rejected bar's own value would otherwise judge every bar after
            // it against a value further back than intended.
            if ($verdict['valid']) {
                $date = $bar->date->toDateString();

                $rows[] = [
                    'name' => $bar->name,
                    'slug' => $slug,
                    'trade_date' => $date,
                    'value' => $bar->value,
                    'change_amount' => $previousValue === null
                        ? $bar->changeAmount
                        : round($bar->value - $previousValue, 4),
                    'change_percent' => $previousValue !== null && $previousValue > 0
                        ? round(($bar->value - $previousValue) / $previousValue * 100, 4)
                        : $bar->changePercent,
                    'total_turnover' => $bar->totalTurnover,
                    'source' => $this->indexHistory->key(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $existing->has($date) ? $updated++ : $created++;
            }

            $previousValue = $bar->value;
            $previousDate = $bar->date;
        }

        foreach (array_chunk($rows, 250) as $chunk) {
            MarketIndex::upsert(
                $chunk,
                ['slug', 'trade_date'],
                ['name', 'value', 'change_amount', 'change_percent', 'total_turnover', 'source', 'updated_at'],
            );
        }

        return [$created, $updated];
    }

    /**
     * Backfill historical daily bars from the chart feed.
     *
     * @param  list<string>|null  $symbols  Null backfills every active equity.
     * @param  callable|null  $onProgress  fn(string $symbol, int $written): void
     */
    public function backfillHistory(
        ?array $symbols = null,
        int $days = 400,
        ?int $limit = null,
        ?callable $onProgress = null,
        ?Carbon $since = null,
    ): ScrapeRun {
        $run = ScrapeRun::create([
            'type' => 'history_backfill',
            'source' => $this->history->key(),
            'status' => ScrapeRun::STATUS_RUNNING,
            'started_at' => Carbon::now(),
        ]);

        $startedAt = microtime(true);

        $query = Stock::query()->active();

        if ($symbols !== null) {
            $query->whereIn('symbol', array_map('strtoupper', $symbols));
        } else {
            // Debentures and bonds have no meaningful chart history.
            $query->whereIn('instrument_type', ['equity', 'promoter', 'mutual_fund']);
        }

        $stocks = $query->orderBy('symbol')->limit($limit ?? 10000)->get();

        $created = 0;
        $updated = 0;
        $empty = 0;
        $failures = [];
        $now = Carbon::now();

        foreach ($stocks as $stock) {
            try {
                $bars = $this->history->fetchHistory($stock->symbol, $days, $since);
            } catch (Throwable $e) {
                Log::channel('scraper')->warning('History backfill failed for symbol', [
                    'symbol' => $stock->symbol,
                    'error' => $e->getMessage(),
                ]);

                if (count($failures) < 25) {
                    $failures[] = ['symbol' => $stock->symbol, 'reason' => $e->getMessage()];
                }

                continue;
            }

            if ($bars === []) {
                $empty++;

                if ($onProgress !== null) {
                    $onProgress($stock->symbol, 0);
                }

                continue;
            }

            // Existing rows for this stock, so the run report can distinguish
            // new history from re-fetched history.
            $existing = StockPrice::where('stock_id', $stock->id)
                ->pluck('trade_date')
                ->map(static fn ($date): string => Carbon::parse($date)->toDateString())
                ->flip();

            $rows = [];

            // Seed from the session before the window so the oldest bar keeps a
            // real previous close. Starting at null would blank the change
            // columns on a row that already had them, every time a backfill runs.
            $previousClose = StockPrice::where('stock_id', $stock->id)
                ->where('trade_date', '<', $bars[0]->date->toDateString())
                ->orderByDesc('trade_date')
                ->value('close');
            $previousClose = $previousClose === null ? null : (float) $previousClose;

            foreach ($bars as $bar) {
                $date = $bar->date->toDateString();

                $rows[] = [
                    'stock_id' => $stock->id,
                    'trade_date' => $date,
                    'open' => $bar->open,
                    'high' => $bar->high,
                    'low' => $bar->low,
                    'close' => $bar->close,
                    'previous_close' => $previousClose,
                    'change_amount' => $previousClose === null
                        ? null : round($bar->close - $previousClose, 2),
                    'change_percent' => $previousClose === null || $previousClose <= 0
                        ? null : round(($bar->close - $previousClose) / $previousClose * 100, 2),
                    'volume' => $bar->volume,
                    // The chart feed carries no turnover; approximating it from
                    // volume x close would be a fabricated number, so it stays
                    // zero and the daily scrape fills it for current sessions.
                    'turnover' => 0,
                    'source' => $this->history->key(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $existing->has($date) ? $updated++ : $created++;
                $previousClose = $bar->close;
            }

            foreach (array_chunk($rows, 250) as $chunk) {
                StockPrice::upsert(
                    $chunk,
                    ['stock_id', 'trade_date'],
                    // Deliberately does NOT overwrite turnover: the daily scrape
                    // has the real figure and the chart feed does not.
                    ['open', 'high', 'low', 'close', 'previous_close',
                        'change_amount', 'change_percent', 'volume', 'updated_at'],
                );
            }

            if ($onProgress !== null) {
                $onProgress($stock->symbol, count($rows));
            }
        }

        $run->update([
            'status' => $failures === [] ? ScrapeRun::STATUS_SUCCESS : ScrapeRun::STATUS_PARTIAL,
            'rows_found' => $stocks->count(),
            'rows_created' => $created,
            'rows_updated' => $updated,
            'rows_rejected' => $empty,
            'rejections' => $failures,
            'finished_at' => Carbon::now(),
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        return $run->refresh();
    }

    /**
     * Crawl company pages to fill in names, sectors and fundamentals.
     *
     * @param  list<string>|null  $symbols  Null crawls every active stock.
     */
    public function scrapeFundamentals(?array $symbols = null, ?int $limit = null): ScrapeRun
    {
        $run = ScrapeRun::create([
            'type' => 'fundamentals',
            'status' => ScrapeRun::STATUS_RUNNING,
            'started_at' => Carbon::now(),
        ]);

        $startedAt = microtime(true);

        $query = Stock::query()->active();

        if ($symbols !== null) {
            $query->whereIn('symbol', array_map('strtoupper', $symbols));
        }

        // Refresh the least recently touched first, so an interrupted run makes
        // progress across the market instead of re-crawling the same head.
        //
        // Ordered by the fundamentals row rather than the stock: persisting a
        // scrip that yields no stock-level attribute leaves stocks.updated_at
        // untouched, so ordering by that column parked the same starved head of
        // the market at the front of every run and the tail was never reached.
        $stocks = $query
            ->select('stocks.*')
            ->selectSub(
                StockFundamental::query()
                    ->selectRaw('MAX(updated_at)')
                    ->whereColumn('stock_fundamentals.stock_id', 'stocks.id'),
                'fundamentals_updated_at',
            )
            ->orderByRaw('fundamentals_updated_at IS NOT NULL, fundamentals_updated_at ASC')
            ->limit($limit ?? 10000)
            ->get();

        $sources = $this->sources->fundamentalSources();
        $created = 0;
        $updated = 0;
        $failed = 0;
        $failures = [];

        // Counted separately from $failed. A row whose BS fiscal period cannot
        // be converted is still a good row — every figure on it persisted — it
        // just carries no reporting date, so calling it a failed crawl would
        // misreport a successful one.
        $undated = 0;

        // One cheap request backfills every company name.
        $this->backfillCompanyNames();

        $stocks->load('sector');

        foreach ($stocks as $stock) {
            $row = null;

            // Every source is asked side by side. They live on different hosts
            // (ShareSansar, Merolagani), so their waits can overlap; the
            // fetcher still spaces and serialises each host on its own, so two
            // sources on one host take turns exactly as before. Results come
            // back in source order, which the merge below depends on.
            $candidates = $this->http->concurrently(array_map(
                static fn (FundamentalsSource $source): Closure => static fn (): ?FundamentalRow => $source->fetchFundamentals($stock->symbol, $stock->sector?->name),
                $sources,
            ));

            // Merge across sources instead of stopping at the first that
            // answers — no single site publishes the whole metric set, and
            // first-wins silently discarded whatever the others knew.
            foreach ($sources as $index => $source) {
                $candidate = $candidates[$index] ?? null;

                if ($candidate instanceof Throwable) {
                    // One source being down must not cost us the others' data
                    // for this scrip, nor abort the remaining symbols.
                    Log::channel('scraper')->warning('Fundamentals source failed', [
                        'source' => $source->key(),
                        'symbol' => $stock->symbol,
                        'error' => $candidate->getMessage(),
                    ]);

                    continue;
                }

                if (! $candidate instanceof FundamentalRow || $candidate->isEmpty()) {
                    continue;
                }

                $row = $row === null ? $candidate : $row->mergeWith($candidate);
            }

            if ($row === null || $row->isEmpty()) {
                $failed++;

                if (count($failures) < 25) {
                    $failures[] = ['symbol' => $stock->symbol, 'reason' => 'no usable fundamentals found'];
                }

                continue;
            }

            try {
                $dating = $this->persistFundamentals($stock, $row, $created, $updated);

                if (! $dating->isDated()) {
                    $undated++;

                    if (count($failures) < 25) {
                        $failures[] = [
                            'symbol' => $stock->symbol,
                            'reason' => 'reported_on left null — '.$dating->rejection,
                        ];
                    }
                }
            } catch (Throwable $e) {
                $failed++;

                Log::channel('scraper')->warning('Failed to persist fundamentals', [
                    'symbol' => $stock->symbol,
                    'error' => $e->getMessage(),
                ]);

                if (count($failures) < 25) {
                    $failures[] = ['symbol' => $stock->symbol, 'reason' => $e->getMessage()];
                }
            }
        }

        $run->update([
            'status' => $failed > 0 ? ScrapeRun::STATUS_PARTIAL : ScrapeRun::STATUS_SUCCESS,
            'rows_found' => $stocks->count(),
            'rows_created' => $created,
            'rows_updated' => $updated,
            'rows_rejected' => $failed,
            'rejections' => $failures,
            'finished_at' => Carbon::now(),
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        if ($undated > 0) {
            Log::channel('scraper')->info('Fundamentals rows left without a reporting date', [
                'run_id' => $run->id,
                'undated' => $undated,
                'observed_through' => $this->observedThrough()?->toDateString(),
            ]);
        }

        return $run->refresh();
    }

    /**
     * Cash dividend yield, derived rather than crawled.
     *
     * NEPSE quotes dividends as a percentage of par value, and par is NPR 100
     * for effectively every listed scrip — so a 12.5% cash dividend is NPR
     * 12.50 per share. Bonus shares are deliberately excluded: they dilute
     * rather than pay, and folding them in overstates the income a holder
     * actually receives.
     */
    private function cashDividendYield(FundamentalRow $row, ?float $lastPrice): ?float
    {
        if ($row->cashDividendPercent === null || $lastPrice === null || $lastPrice <= 0) {
            return null;
        }

        $parValue = Numeric::parse($row->raw['paid up'] ?? null) ?? 100.0;

        if ($parValue <= 0) {
            return null;
        }

        return round($row->cashDividendPercent / 100 * $parValue / $lastPrice * 100, 4);
    }

    /**
     * Return on equity, derived rather than crawled.
     *
     * Neither source publishes ROE, but both halves of the identity are on the
     * page: EPS and book value are per-share figures over the same share
     * count, so EPS / book value is exactly net income / shareholders' equity.
     */
    private function returnOnEquity(FundamentalRow $row): ?float
    {
        if ($row->eps === null || $row->bookValue === null || $row->bookValue <= 0) {
            return null;
        }

        return round($row->eps / $row->bookValue * 100, 4);
    }

    /**
     * Distributable earnings per share, derived when the filing did not give it.
     *
     * Retained earnings are the pool a company may actually pay out; per share,
     * that is the ceiling on the next dividend. Deliberately not EPS, which is a
     * single period's profit and says nothing about what has accumulated.
     */
    private function distributableEps(FundamentalRow $row): ?float
    {
        if ($row->distributableEps !== null) {
            return $row->distributableEps;
        }

        $shares = $row->outstandingShares ?? $row->listedShares;

        if ($row->retainedEarnings === null || $shares === null || $shares <= 0) {
            return null;
        }

        return round($row->retainedEarnings / $shares, 4);
    }

    /**
     * DEPS as a percentage of the market price — the "DEPS yield" published
     * comparisons lead with, because it answers how much accumulated
     * distributable profit a rupee of share price is buying.
     */
    private function distributableEpsYield(?float $distributableEps, ?float $lastPrice): ?float
    {
        if ($distributableEps === null || $lastPrice === null || $lastPrice <= 0) {
            return null;
        }

        return round($distributableEps / $lastPrice * 100, 4);
    }

    /**
     * Reject ratios that cannot be true rather than storing them.
     *
     * The filings are transcribed by hand at the source and occasionally land a
     * decimal in the wrong place; an ROE of 900% then propagates into the
     * prompt as fact. Bounds are deliberately loose — wide enough to admit a
     * genuinely extraordinary year, tight enough to catch a transcription slip.
     */
    private function plausibleRatio(?float $value, float $min, float $max): ?float
    {
        if ($value === null) {
            return null;
        }

        return $value >= $min && $value <= $max ? $value : null;
    }

    public function observedThrough(): ?Carbon
    {
        if (! $this->observedThroughResolved) {
            $latest = StockPrice::max('trade_date');

            $this->observedThrough = $latest === null
                ? null
                : Carbon::parse((string) $latest)->startOfDay();

            $this->observedThroughResolved = true;
        }

        return $this->observedThrough;
    }

    /**
     * Date a crawled row, preferring anything the page said outright.
     *
     * A source that can read a filing date off the page should win over a
     * conversion, so $row->reportedOn is honoured when present and only the
     * BS fiscal-period string is converted otherwise. Today no source publishes
     * one — the quarterly report names its period in the table header and
     * nowhere states a date — but the precedence belongs here rather than in
     * whichever source manages it first.
     */
    private function dateFundamentals(FundamentalRow $row): FiscalPeriodDating
    {
        if ($row->reportedOn !== null && trim($row->reportedOn) !== '') {
            return FiscalPeriodDating::fromPage($row->fiscalPeriod, Carbon::parse($row->reportedOn));
        }

        return $this->fiscalPeriods->date($row->fiscalPeriod, $this->observedThrough());
    }

    private function persistFundamentals(Stock $stock, FundamentalRow $row, int &$created, int &$updated): FiscalPeriodDating
    {
        $sector = Sector::resolve($row->sector);
        $lastPrice = $stock->last_price === null ? null : (float) $stock->last_price;

        // Neither source publishes market cap, but both halves are in hand:
        // listed shares from the company page, price from the daily scrape.
        $marketCap = $row->marketCapitalization;

        if ($marketCap === null && $row->listedShares !== null && $lastPrice !== null && $lastPrice > 0) {
            $marketCap = round($row->listedShares * $lastPrice, 2);
        }

        $stockAttributes = array_filter([
            'sector_id' => $sector?->id,
            // The filing's share count is the authoritative one; the market
            // page's "listed shares" lags a bonus issue by weeks.
            'listed_shares' => $row->outstandingShares ?? $row->listedShares,
            'market_capitalization' => $marketCap,
        ], static fn ($value): bool => $value !== null);

        if ($stockAttributes !== []) {
            $stock->fill($stockAttributes)->save();
        }

        $existing = StockFundamental::where('stock_id', $stock->id)
            ->where('fiscal_period', $row->fiscalPeriod)
            ->first();

        $distributableEps = $this->distributableEps($row);
        $dating = $this->dateFundamentals($row);

        $attributes = [
            // Left NULL, with the reason recorded on the run, when the period
            // cannot honestly be dated — a fabricated reporting date is worse
            // than none, because every "as at" lookup would believe it.
            'reported_on' => $dating->reportedOn(),
            'reported_on_precision' => $dating->precision(),
            'eps' => $row->eps,
            'pe_ratio' => $row->peRatio,
            'book_value' => $row->bookValue,
            'pb_ratio' => $row->pbRatio,
            'dividend_percent' => $row->dividendPercent,
            'bonus_percent' => $row->bonusPercent,
            'cash_dividend_percent' => $row->cashDividendPercent,
            'dividend_yield' => $row->dividendYield ?? $this->cashDividendYield($row, $lastPrice),
            // The filed ROE is the regulator's own figure and beats EPS/book
            // value, which silently assumes no change in share count.
            'roe' => $this->plausibleRatio($row->roe ?? $this->returnOnEquity($row), -200.0, 200.0),
            'roa' => $this->plausibleRatio($row->roa, -100.0, 100.0),
            'net_profit' => $row->netProfit,
            'net_worth' => $row->netWorth ?? $row->totalEquity,
            'paid_up_capital' => $row->paidUpCapital,
            'share_capital' => $row->shareCapital,
            'retained_earnings' => $row->retainedEarnings,
            'total_equity' => $row->totalEquity,
            'total_assets' => $row->totalAssets,
            'outstanding_shares' => $row->outstandingShares,
            'distributable_eps' => $distributableEps,
            'distributable_eps_yield' => $this->distributableEpsYield($distributableEps, $lastPrice),
            'reserve' => $row->reserve,
            'npl_percent' => $this->plausibleRatio($row->nplPercent, 0.0, 100.0),
            'capital_adequacy_ratio' => $this->plausibleRatio($row->capitalAdequacyRatio, -100.0, 100.0),
            'base_rate' => $this->plausibleRatio($row->baseRate, 0.0, 100.0),
            'source' => 'crawler',
            'raw' => $row->raw,
        ];

        if ($existing === null) {
            StockFundamental::create(array_merge($attributes, [
                'stock_id' => $stock->id,
                'fiscal_period' => $row->fiscalPeriod,
            ]));
            $created++;
        } else {
            $existing->fill($attributes)->save();
            $updated++;
        }

        return $dating;
    }

    /**
     * Fill in company names from the embedded company list.
     */
    private function backfillCompanyNames(): void
    {
        $source = $this->sources->make('sharesansar');

        if (! $source instanceof SharesansarSource) {
            return;
        }

        $names = $source->fetchCompanyNames();

        if ($names === []) {
            return;
        }

        // Only touch rows whose name is still the placeholder symbol, so a
        // manually corrected name is never overwritten.
        Stock::whereColumn('name', 'symbol')
            ->orWhereNull('name')
            ->chunkById(200, function ($stocks) use ($names): void {
                foreach ($stocks as $stock) {
                    if (isset($names[$stock->symbol]) && $names[$stock->symbol] !== $stock->name) {
                        $stock->update(['name' => $names[$stock->symbol]]);
                    }
                }
            });
    }

    /**
     * NEPSE trades Sunday-Thursday. On a Friday or Saturday the freshest
     * published table is still Thursday's.
     */
    public function latestTradingDay(?Carbon $from = null): Carbon
    {
        $date = ($from ?? Carbon::now(config('nepse.timezone')))->copy()->startOfDay();
        $tradingDays = (array) config('nepse.trading_days');

        // Bounded loop: a week is always enough to find the previous session.
        for ($i = 0; $i < 7; $i++) {
            if (in_array((int) $date->dayOfWeekIso, $tradingDays, true)) {
                return $date;
            }

            $date->subDay();
        }

        return $date;
    }

    public function isTradingDay(Carbon $date): bool
    {
        return in_array((int) $date->dayOfWeekIso, (array) config('nepse.trading_days'), true);
    }

    /**
     * NEPSE encodes instrument type in the ticker suffix.
     */
    private function guessInstrumentType(string $symbol): string
    {
        return match (true) {
            (bool) preg_match('/^\d+(\.\d+)?[A-Z]/', $symbol) => 'debenture',
            str_ends_with($symbol, 'PO') => 'promoter',
            (bool) preg_match('/(MF|FUND)$/', $symbol) => 'mutual_fund',
            default => 'equity',
        };
    }
}
