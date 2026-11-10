<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\ScrapeException;
use App\Jobs\ScrapeDailyPricesJob;
use App\Services\Scraper\NepseScraperService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ScrapeNepseCommand extends Command
{
    protected $signature = 'nepse:scrape
        {--date= : Trading date to scrape (YYYY-MM-DD). Defaults to the latest session.}
        {--source= : Force a single source (sharesansar, merolagani).}
        {--queue : Dispatch as a queued job instead of running inline.}
        {--no-indicators : Skip the indicator recalculation that normally follows.}';

    protected $description = 'Scrape the NEPSE daily price table and store it';

    public function handle(NepseScraperService $scraper): int
    {
        $date = $this->option('date');
        $source = $this->option('source');

        if ($this->option('queue')) {
            ScrapeDailyPricesJob::dispatch($date, $source, ! $this->option('no-indicators'));
            $this->info('Scrape job dispatched to the queue.');

            return self::SUCCESS;
        }

        $this->info('Scraping NEPSE daily prices'.($date !== null ? " for {$date}" : ' for the latest session').'...');

        try {
            $run = $scraper->scrapeDailyPrices(
                $date !== null ? Carbon::parse($date) : null,
                $source,
            );
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
            $this->warn("{$run->rows_rejected} rows were rejected by validation:");

            foreach (array_slice((array) $run->rejections, 0, 10) as $rejection) {
                $this->line("  - {$rejection['symbol']}: {$rejection['reason']}");
            }
        }

        return self::SUCCESS;
    }
}
