<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Scraper\NepseScraperService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Crawls company detail pages for names, sectors and fundamentals.
 *
 * Deliberately long-running and infrequent: it makes one request per scrip with
 * a politeness delay, so a full pass over the market takes several minutes.
 */
class ScrapeFundamentalsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 3600;

    public array $backoff = [600];

    /**
     * @param  list<string>|null  $symbols
     */
    public function __construct(
        public readonly ?array $symbols = null,
        public readonly ?int $limit = null,
    ) {}

    public function uniqueId(): string
    {
        return 'scrape-fundamentals:'.($this->symbols === null ? 'all' : implode(',', $this->symbols));
    }

    public function uniqueFor(): int
    {
        return 7200;
    }

    public function handle(NepseScraperService $scraper): void
    {
        $run = $scraper->scrapeFundamentals($this->symbols, $this->limit);

        Log::channel('scraper')->info('Fundamentals scrape finished', [
            'run_id' => $run->id,
            'status' => $run->status,
            'found' => $run->rows_found,
            'created' => $run->rows_created,
            'updated' => $run->rows_updated,
            'failed' => $run->rows_rejected,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('scraper')->error('Fundamentals scrape permanently failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
