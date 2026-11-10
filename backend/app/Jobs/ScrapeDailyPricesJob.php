<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Scraper\NepseScraperService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scrapes the daily price table, then fans out indicator recalculation.
 */
class ScrapeDailyPricesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** Wait 2m, then 5m, then 15m — these sites publish on their own schedule. */
    public array $backoff = [120, 300, 900];

    public function __construct(
        public readonly ?string $date = null,
        public readonly ?string $source = null,
        public readonly bool $calculateIndicators = true,
    ) {}

    /**
     * One price scrape per day at a time; a retry or an overlapping schedule
     * tick must not run two crawls against the same site concurrently.
     */
    public function uniqueId(): string
    {
        return 'scrape-daily-prices:'.($this->date ?? 'latest');
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function handle(NepseScraperService $scraper): void
    {
        $date = $this->date !== null ? Carbon::parse($this->date) : null;

        $run = $scraper->scrapeDailyPrices($date, $this->source);

        Log::channel('scraper')->info('Scrape job finished', [
            'run_id' => $run->id,
            'status' => $run->status,
            'created' => $run->rows_created,
            'updated' => $run->rows_updated,
        ]);

        if ($this->calculateIndicators) {
            CalculateIndicatorsJob::dispatch($run->trade_date?->toDateString());
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('scraper')->error('Daily price scrape permanently failed', [
            'date' => $this->date ?? 'latest',
            'source' => $this->source,
            'error' => $exception->getMessage(),
        ]);
    }
}
