<?php

declare(strict_types=1);

use App\Jobs\AnalyzePortfolioJob;
use App\Jobs\BackfillHistoryDripJob;
use App\Jobs\BackfillIndexHistoryJob;
use App\Jobs\GenerateBuyRecommendationsJob;
use App\Jobs\QueueStockAnalysisJob;
use App\Jobs\RecordWorkerHeartbeatJob;
use App\Jobs\ScrapeCorporateActionsJob;
use App\Jobs\ScrapeDailyPricesJob;
use App\Jobs\ScrapeFundamentalsJob;
use App\Models\Portfolio;
use App\Models\ScrapeRun;
use App\Models\StockPrice;
use App\Services\Operations\Heartbeat;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Data pipeline schedule
|--------------------------------------------------------------------------
|
| NEPSE closes at 15:00 NPT; the published price tables settle shortly after.
|
| The scrape itself runs every day (see config('nepse.schedule.scrape')) -
| NOT only Sunday-Thursday. NEPSE's trading week has already changed once (it
| moved to Monday-Friday) and a cron expression cannot be effective-dated, so
| baking a weekday restriction in here would silently stop scraping on a real
| trading day after the next change, or keep firing pointlessly on a day that
| stopped being one. A run on a closed day just observes and records "no
| data" (App\Services\Scraper\Backfill\MarketCalendarClassifier) instead of
| being skipped by a guess.
|
| Passes that only make sense once today's prices actually exist - the
| analysis safety net below, the portfolio pass - check for a bar on today's
| date instead, via $todayHasTradingData.
|
| Requires a running scheduler:  php artisan schedule:work
| and at least one queue worker: php artisan queue:work redis
|
*/

// Whether today already has at least one observed bar - the same "a bar
// proves a trading day" rule the backfill's calendar classifier uses, kept
// here as a plain query since it needs to be real-time (today's own
// classification is not settled in market_calendar_days until a later
// backfill drip observes it).
$todayHasTradingData = static fn (): bool => StockPrice::query()
    ->whereDate('trade_date', now(config('nepse.timezone'))->toDateString())
    ->exists();

// 1. Scrape the price table twice a day — midday (12:00) for an intraday
//    read, and at the close (15:00) for the settled figures. Each run chains
//    into indicator calculation, which queues the Claude analysis.
//
//    Both passes upsert the same (stock_id, trade_date) row, so the close simply
//    supersedes the midday snapshot instead of creating duplicate history.
foreach ((array) config('nepse.schedule.scrape') as $index => $cron) {
    Schedule::job(new ScrapeDailyPricesJob)
        ->cron((string) $cron)
        ->timezone((string) config('nepse.timezone'))
        ->name('nepse-scrape-daily-prices-'.$index)
        // Skip a run rather than pile up if the previous one is still going.
        ->withoutOverlapping(30)
        ->onOneServer();
}

// 2. Safety net: if the chained dispatch was interrupted, queue any stock that
//    still has no signal for today. Only worth doing once today's scrape has
//    actually found something to analyse.
Schedule::job(new QueueStockAnalysisJob)
    ->cron((string) config('nepse.schedule.analysis'))
    ->timezone((string) config('nepse.timezone'))
    ->name('nepse-queue-stock-analysis')
    ->when($todayHasTradingData)
    ->withoutOverlapping(30)
    ->onOneServer();

// 3. Fundamentals change quarterly at most, and the crawl is one request per
//    company — weekly, on a non-trading day, out of hours.
Schedule::job(new ScrapeFundamentalsJob)
    ->weeklyOn(5, '02:00') // Friday, a NEPSE holiday
    ->timezone((string) config('nepse.timezone'))
    ->name('nepse-scrape-fundamentals')
    ->withoutOverlapping(120)
    ->onOneServer();

// 4. Refresh every portfolio's health analysis and buy recommendations once the
//    day's signals exist. Runs every day; skipped when today never produced a
//    bar (a closure, or the scrape has not run yet) rather than trusting a
//    fixed Sunday-Thursday assumption that NEPSE's own trading week no longer
//    matches.
Schedule::call(function (): void {
    Portfolio::query()->select('id')->chunkById(50, function ($portfolios): void {
        foreach ($portfolios as $portfolio) {
            AnalyzePortfolioJob::dispatch($portfolio->id);
            GenerateBuyRecommendationsJob::dispatch($portfolio->id);
        }
    });
})
    ->cron('0 18 * * *')
    ->timezone((string) config('nepse.timezone'))
    ->name('nepse-portfolio-analysis')
    ->when($todayHasTradingData)
    ->withoutOverlapping(30)
    ->onOneServer();

// 5. Housekeeping — keep the scrape audit log bounded.
Schedule::call(function (): void {
    ScrapeRun::where('created_at', '<', now()->subDays(90))->delete();
})
    ->weekly()
    ->name('nepse-prune-scrape-runs')
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Corporate calendar
|--------------------------------------------------------------------------
|
| Book closes, AGMs, dividends, rights and issues. Deliberately no weekday
| field and no trading-day guard: issuers publish notices on non-trading days,
| and the job is idempotent, so a run that finds nothing costs one request.
|
*/

Schedule::job(new ScrapeCorporateActionsJob)
    ->dailyAt('19:00')
    ->timezone((string) config('nepse.timezone'))
    ->name('nepse-scrape-corporate-actions')
    ->withoutOverlapping(60)
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Horizon
|--------------------------------------------------------------------------
*/

// Horizon's metrics graphs are built from periodic snapshots; without this the
// dashboard's throughput and runtime charts stay empty.
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->name('horizon-snapshot')
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Self-healing historical backfill
|--------------------------------------------------------------------------
|
| Works the whole market towards config('nepse.backfill.target_years') of
| history on its own — a small, budgeted slice of symbols per tick, never a
| burst — instead of an operator remembering to run
| `nepse:backfill --days=1825` per scrip. It also picks up any symbol added
| later and re-checks gaps left by a failed scrape or downtime.
|
| The default schedule sits in hours the live pipeline is idle: clear of the
| 12:00/15:00 scrapes, the 15:30 analysis safety net and the 18:00 portfolio
| pass above, so it never competes with the daily crawl for a queue slot.
| Adjust with NEPSE_BACKFILL_SCHEDULE (cron expression).
*/
Schedule::job(new BackfillHistoryDripJob)
    ->cron((string) config('nepse.backfill.schedule'))
    ->timezone((string) config('nepse.timezone'))
    ->name('nepse-backfill-history-drip')
    ->withoutOverlapping(10)
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Index history top-up
|--------------------------------------------------------------------------
|
| Indices themselves are captured every daily price scrape (see
| NepseScraperService::scrapeIndices(), called from scrapeDailyPrices()), so
| this is not a drip in the BackfillHistoryDripJob sense — it exists only to
| catch a config('nepse.index_backfill.top_up_days')-wide window back up
| after downtime or a missed run, for the fixed ~18-index vocabulary
| App\Services\Scraper\IndexCatalog defines. Weekly and off-hours, same as
| the fundamentals crawl.
*/
Schedule::job(new BackfillIndexHistoryJob)
    ->cron((string) config('nepse.index_backfill.schedule'))
    ->timezone((string) config('nepse.timezone'))
    ->name('nepse-backfill-index-history')
    ->withoutOverlapping(30)
    ->onOneServer();

// A scheduler heartbeat proves dispatch is running; separate queued probes prove
// each worker pool is draining. They do not contact market or AI providers.
Schedule::call(function (): void {
    app(Heartbeat::class)->record('scheduler');
    RecordWorkerHeartbeatJob::dispatch('pipeline');
    RecordWorkerHeartbeatJob::dispatch('ai');
})->everyMinute()->name('operations-heartbeats')->onOneServer();
