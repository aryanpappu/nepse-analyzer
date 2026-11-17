<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ScrapeRun;
use App\Services\Scraper\NepseScraperService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One mid-session pass: write today's provisional bar, then rescore indicators
 * and the playbook.
 *
 * AI analysis is deliberately left out. QueueStockAnalysisJob analyses a scrip
 * once per session, so letting a half-finished session through would spend
 * the day's call on partial data and then skip the real close.
 *
 * Not ShouldBeUnique on purpose: the manual refresh dispatches this as the
 * head of a chain, and a unique job whose lock is held drops the whole chain
 * silently. A duplicate pass costs one request; the schedule already uses
 * withoutOverlapping().
 */
class ScrapeLivePricesJob implements ShouldQueue
{
    use Queueable;

    // No retries: the next pass is fifteen minutes away and a retry would
    // only arrive with older figures than it.
    public int $tries = 1;

    public int $timeout = 300;

    public function handle(NepseScraperService $scraper): void
    {
        try {
            $run = $scraper->scrapeLivePrices();
        } catch (Throwable $e) {
            // Recorded as a failed run where one could be created. Not rethrown:
            // a retry would only bring older figures than the next pass, and
            // the manual refresh chains the settled scrape after this job,
            // which must still run when the live page is unreadable.
            Log::channel('scraper')->warning('Live scrape job failed', ['error' => $e->getMessage()]);

            return;
        }

        Log::channel('scraper')->info('Live scrape job finished', [
            'run_id' => $run->id,
            'status' => $run->status,
            'created' => $run->rows_created,
            'updated' => $run->rows_updated,
        ]);

        if (in_array($run->status, [ScrapeRun::STATUS_SUCCESS, ScrapeRun::STATUS_PARTIAL], true)) {
            CalculateIndicatorsJob::dispatch($run->trade_date?->toDateString(), null, queueAnalysis: false);
        }
    }
}
