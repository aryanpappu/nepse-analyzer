<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ScrapeFundamentalsJob;
use App\Services\Scraper\NepseScraperService;
use Illuminate\Console\Command;

class ScrapeFundamentalsCommand extends Command
{
    protected $signature = 'nepse:fundamentals
        {--symbol=* : Only crawl these tickers (repeatable).}
        {--limit= : Cap how many companies are crawled this run.}
        {--queue : Dispatch as a queued job instead of running inline.}';

    protected $description = 'Crawl company pages for names, sectors, EPS, P/E and dividend data';

    public function handle(NepseScraperService $scraper): int
    {
        $symbols = (array) $this->option('symbol');
        $symbols = $symbols === [] ? null : $symbols;
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        if ($this->option('queue')) {
            ScrapeFundamentalsJob::dispatch($symbols, $limit);
            $this->info('Fundamentals crawl dispatched to the queue.');

            return self::SUCCESS;
        }

        $this->info('Crawling company pages. This is rate limited, so it takes a while...');

        $run = $scraper->scrapeFundamentals($symbols, $limit);

        $this->newLine();
        $this->table(
            ['Status', 'Companies', 'Created', 'Updated', 'Failed', 'Duration'],
            [[
                $run->status,
                $run->rows_found,
                $run->rows_created,
                $run->rows_updated,
                $run->rows_rejected,
                round(($run->duration_ms ?? 0) / 1000, 1).' s',
            ]],
        );

        if ($run->rows_rejected > 0) {
            $this->warn('Some companies could not be crawled:');

            foreach (array_slice((array) $run->rejections, 0, 10) as $failure) {
                $this->line("  - {$failure['symbol']}: {$failure['reason']}");
            }
        }

        return self::SUCCESS;
    }
}
