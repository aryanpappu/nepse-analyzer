<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\ScrapeException;
use App\Http\Controllers\Api\PerformanceController;
use App\Models\MarketIndex;
use App\Models\Portfolio;
use App\Services\Scraper\IndexCatalog;
use App\Services\Scraper\NepseScraperService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ScrapeIndicesCommand extends Command
{
    protected $signature = 'nepse:indices
        {--backfill : Backfill historical index bars instead of the current session.}
        {--days= : Calendar days of history to backfill (0 = since inception). Only with --backfill; defaults to config(nepse.index_backfill.default_days).}
        {--index=* : Only these indices (repeatable; e.g. --index="NEPSE Index" --index=Sensitive). Only with --backfill.}
        {--source= : Force a single price source for the current-session fetch (e.g. sharesansar).}
        {--show : Print the latest stored value for every index instead of fetching anything.}';

    protected $description = 'Fetch and inspect NEPSE/ShareSansar index data (NEPSE Index, Sensitive Index, sector sub-indices)';

    public function handle(NepseScraperService $scraper): int
    {
        if ($this->option('show')) {
            $this->showLatest();

            return self::SUCCESS;
        }

        return $this->option('backfill')
            ? $this->runBackfill($scraper)
            : $this->runDailyCapture($scraper);
    }

    private function runDailyCapture(NepseScraperService $scraper): int
    {
        $this->info('Fetching current-session NEPSE indices...');

        try {
            $run = $scraper->scrapeIndices(null, $this->option('source'));
        } catch (ScrapeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Source', 'Trade date', 'Status', 'Found', 'Created', 'Updated', 'Rejected', 'Duration'],
            [[
                $run->source,
                $run->trade_date?->toDateString(),
                $run->status,
                $run->rows_found,
                $run->rows_created,
                $run->rows_updated,
                $run->rows_rejected,
                $run->duration_ms.' ms',
            ]],
        );

        if ($run->rows_rejected > 0) {
            $this->warn("{$run->rows_rejected} index readings were rejected by validation:");

            foreach (array_slice((array) $run->rejections, 0, 10) as $rejection) {
                $this->line("  - {$rejection['name']}: {$rejection['reason']}");
            }
        }

        $this->invalidatePortfolioAnswers();
        $this->showLatest();

        return self::SUCCESS;
    }

    /**
     * Drop the cached benchmark and attribution answers for every portfolio.
     *
     * Those are cached per portfolio behind a version counter that only bumps
     * on a *portfolio* write — a trade, a cash correction. New index history
     * changes the answer without touching any portfolio, so without this a
     * deepened index silently keeps serving the old result. That is not
     * hypothetical: backfilling history back to 2021 turned every sector
     * comparison from "not computed" into a real figure, and the screen kept
     * showing n/a until the cache was bumped by hand.
     */
    private function invalidatePortfolioAnswers(): void
    {
        $portfolios = Portfolio::all();

        foreach ($portfolios as $portfolio) {
            Cache::forget("portfolio:summary:{$portfolio->id}");
            PerformanceController::invalidateBenchmark($portfolio);
        }

        if ($portfolios->isNotEmpty()) {
            $this->line(sprintf(
                '  Cleared cached benchmark and attribution answers for %d portfolio(s) — new index history changes them.',
                $portfolios->count(),
            ));
        }
    }

    private function runBackfill(NepseScraperService $scraper): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('nepse.index_backfill.default_days');

        $requested = (array) $this->option('index');
        $names = $requested === [] ? null : $requested;

        $expected = $names !== null ? count($names) : count(IndexCatalog::CANONICAL_NAMES);

        $this->info($days > 0
            ? "Backfilling up to {$days} days of history for {$expected} indices."
            : "Backfilling the full available history for {$expected} indices.");
        $this->comment('This is rate limited to stay polite to the source, so expect it to take a while.');
        $this->newLine();

        $bar = $this->output->createProgressBar($expected);
        $bar->start();

        $run = $scraper->backfillIndexHistory(
            $names,
            $days,
            function (string $name, int $written) use ($bar): void {
                $bar->setMessage($name);
                $bar->advance();
            },
        );

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Status', 'Indices', 'Bars created', 'Bars updated', 'No data', 'Duration'],
            [[
                $run->status,
                $run->rows_found,
                number_format($run->rows_created),
                number_format($run->rows_updated),
                $run->rows_rejected,
                round(($run->duration_ms ?? 0) / 1000, 1).' s',
            ]],
        );

        if ($run->rows_rejected > 0) {
            $this->comment("{$run->rows_rejected} indices had no history for the requested window — expected for "
                .'sub-indices ShareSansar only started publishing individually around 2011.');
        }

        if (! empty($run->rejections)) {
            foreach (array_slice((array) $run->rejections, 0, 10) as $failure) {
                $this->line("  - {$failure['index']}: {$failure['reason']}");
            }
        }

        $this->invalidatePortfolioAnswers();

        return self::SUCCESS;
    }

    private function showLatest(): void
    {
        $rows = [];

        foreach (IndexCatalog::CANONICAL_NAMES as $name) {
            $slug = Str::slug($name);
            $index = MarketIndex::where('slug', $slug)->orderByDesc('trade_date')->first();

            if ($index === null) {
                continue;
            }

            $rows[] = [
                $index->name,
                $index->trade_date->toDateString(),
                number_format((float) $index->value, 2),
                $index->change_percent === null ? '-' : sprintf('%+.2f%%', $index->change_percent),
                $index->total_turnover === null ? '-' : number_format((float) $index->total_turnover, 0),
                $index->source,
            ];
        }

        if ($rows === []) {
            $this->comment('No index data stored yet. Run `php artisan nepse:indices` first.');

            return;
        }

        $this->newLine();
        $this->table(['Index', 'Trade date', 'Value', 'Change', 'Turnover', 'Source'], $rows);
    }
}
