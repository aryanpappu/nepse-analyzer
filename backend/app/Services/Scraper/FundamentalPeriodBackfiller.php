<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Models\ScrapeRun;
use App\Models\StockPrice;
use App\Support\NepaliFiscalYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fills `stock_fundamentals.reported_on` for rows already in the database.
 *
 * The forward path — {@see NepseScraperService::persistFundamentals()} — dates
 * every row it writes from now on, but the table already held rows with a BS
 * `fiscal_period` string and no date, which is the whole reason Brinson
 * allocation attribution could not be computed. Those rows need no re-crawl:
 * the period string is already stored, and dating it is arithmetic over
 * {@see NepaliFiscalYear}. This exists so the existing rows can be
 * dated without spending a single request on a free public site.
 *
 * Three deliberate choices:
 *
 * - **Only null dates are touched by default.** A date already stored — read
 *   off a page, or asserted by hand — outranks anything derived here, so
 *   re-running is cheap and never regresses a better answer. `--force`
 *   re-derives everything, for when the conversion table gains a year.
 * - **`updated_at` is left alone.** It is written through the query builder
 *   rather than Eloquent for exactly this reason: `scrapeFundamentals()`
 *   crawls in `fundamentals_updated_at ASC` order so an interrupted run makes
 *   progress across the market, and bumping every row's timestamp would erase
 *   that ordering and re-park the starved head of the market at the front.
 * - **Undated stays undated.** A period that cannot be converted keeps a NULL
 *   date and is counted, with its reason, rather than being given a plausible
 *   guess.
 */
final class FundamentalPeriodBackfiller
{
    /** Rows per chunk. Small enough to stay off the heap, large enough to be one pass. */
    private const CHUNK = 250;

    /** Distinct rejection reasons kept for the run record. */
    private const MAX_SAMPLED_REASONS = 25;

    public function __construct(
        private readonly FiscalPeriodDater $dater = new FiscalPeriodDater,
    ) {}

    /**
     * @param  bool  $dryRun  Measure only — nothing is written, no run recorded.
     * @param  bool  $force  Re-derive rows that already carry a date.
     * @param  ?Carbon  $observedThrough  Latest session we hold data for; a
     *                                    period ending after it has not been
     *                                    filed. Defaults to the newest stored
     *                                    trade date — an observed date, never
     *                                    the local clock.
     */
    public function backfill(bool $dryRun = false, bool $force = false, ?Carbon $observedThrough = null): FundamentalPeriodBackfillReport
    {
        $observedThrough ??= $this->latestObservedSession();

        $run = $dryRun ? null : ScrapeRun::create([
            'type' => 'fundamental_periods',
            'source' => 'derived',
            'trade_date' => $observedThrough?->toDateString(),
            'status' => ScrapeRun::STATUS_RUNNING,
            'started_at' => Carbon::now(),
        ]);

        $startedAt = microtime(true);

        $examined = 0;
        $exact = 0;
        $approximate = 0;
        $undated = 0;
        $written = 0;

        /** @var array<string, array{total: int, exact: int, approximate: int, undated: int, reason: ?string}> $byPeriod */
        $byPeriod = [];

        /** @var array<string, int> $undatedReasons */
        $undatedReasons = [];

        $query = DB::table('stock_fundamentals')
            ->select('id', 'fiscal_period', 'reported_on', 'reported_on_precision');

        if (! $force) {
            $query->whereNull('reported_on');
        }

        $query->orderBy('id')->chunkById(self::CHUNK, function ($rows) use (
            $observedThrough,
            $dryRun,
            &$examined,
            &$exact,
            &$approximate,
            &$undated,
            &$written,
            &$byPeriod,
            &$undatedReasons,
        ): void {
            $updates = [];

            foreach ($rows as $row) {
                $period = (string) ($row->fiscal_period ?? '');
                $examined++;

                $byPeriod[$period] ??= [
                    'total' => 0,
                    'exact' => 0,
                    'approximate' => 0,
                    'undated' => 0,
                    'reason' => null,
                ];
                $byPeriod[$period]['total']++;

                $dating = $this->dater->date($period, $observedThrough);

                if (! $dating->isDated()) {
                    $undated++;
                    $byPeriod[$period]['undated']++;
                    $byPeriod[$period]['reason'] ??= $dating->rejection;

                    $reason = (string) $dating->rejection;
                    $undatedReasons[$reason] = ($undatedReasons[$reason] ?? 0) + 1;

                    continue;
                }

                if ($dating->approximate) {
                    $approximate++;
                    $byPeriod[$period]['approximate']++;
                } else {
                    $exact++;
                    $byPeriod[$period]['exact']++;
                }

                $reportedOn = (string) $dating->reportedOn();
                $precision = (string) $dating->precision();

                // Skip a write that would change nothing — a --force pass over
                // an already-dated table should be a read.
                if ($row->reported_on !== null
                    && str_starts_with((string) $row->reported_on, $reportedOn)
                    && $row->reported_on_precision === $precision) {
                    continue;
                }

                $updates[$reportedOn.'|'.$precision][] = (int) $row->id;
            }

            if ($dryRun) {
                foreach ($updates as $ids) {
                    $written += count($ids);
                }

                return;
            }

            // One UPDATE per distinct (date, precision) pair rather than one per
            // row: a quarter's worth of filings all share a reporting date, so
            // this collapses ~1,000 statements into a couple of dozen.
            foreach ($updates as $key => $ids) {
                [$reportedOn, $precision] = explode('|', $key, 2);

                $written += DB::table('stock_fundamentals')
                    ->whereIn('id', $ids)
                    ->update([
                        'reported_on' => $reportedOn,
                        'reported_on_precision' => $precision,
                    ]);
            }
        });

        uasort($byPeriod, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        arsort($undatedReasons);

        $report = new FundamentalPeriodBackfillReport(
            examined: $examined,
            dated: $exact + $approximate,
            exact: $exact,
            approximate: $approximate,
            undated: $undated,
            written: $written,
            dryRun: $dryRun,
            observedThrough: $observedThrough?->toDateString(),
            byPeriod: $byPeriod,
            undatedReasons: $undatedReasons,
            scrapeRunId: $run?->id,
        );

        $run?->update([
            'status' => $undated > 0 ? ScrapeRun::STATUS_PARTIAL : ScrapeRun::STATUS_SUCCESS,
            'rows_found' => $examined,
            'rows_created' => 0,
            'rows_updated' => $written,
            'rows_rejected' => $undated,
            'rejections' => $this->sampledRejections($undatedReasons),
            'finished_at' => Carbon::now(),
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        Log::channel('scraper')->info('Fundamental reporting dates derived', [
            'run_id' => $run?->id,
            'examined' => $examined,
            'dated' => $report->dated,
            'exact' => $exact,
            'approximate' => $approximate,
            'undated' => $undated,
            'written' => $written,
            'dry_run' => $dryRun,
            'observed_through' => $report->observedThrough,
        ]);

        return $report;
    }

    /**
     * @param  array<string, int>  $undatedReasons
     * @return list<array{symbol: string, reason: string}>
     */
    private function sampledRejections(array $undatedReasons): array
    {
        $rejections = [];

        foreach (array_slice($undatedReasons, 0, self::MAX_SAMPLED_REASONS, true) as $reason => $count) {
            // Same {symbol, reason} shape every other run records, so the
            // existing readers of scrape_runs.rejections keep working; there is
            // no single symbol behind an aggregated reason, hence the count.
            $rejections[] = ['symbol' => $count.' rows', 'reason' => $reason];
        }

        return $rejections;
    }

    /**
     * Newest session we actually hold prices for.
     *
     * Observed, not predicted, and not the clock — the same rule the trading
     * calendar follows. Null when the price table is empty, in which case no
     * future-period check is made at all rather than a fabricated one.
     */
    private function latestObservedSession(): ?Carbon
    {
        $latest = StockPrice::max('trade_date');

        return $latest === null ? null : Carbon::parse((string) $latest)->startOfDay();
    }
}
