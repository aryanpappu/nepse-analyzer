<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Scraper\CorporateCalendarService;
use App\Services\Scraper\Sources\SharesansarCorporateActionSource;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily refresh of the corporate calendar — book closes, general meetings,
 * dividend proposals, rights and public issues.
 *
 * Runs on the pipeline queue, not the AI one: it spends requests, not tokens.
 *
 * The whole crawl is roughly twenty requests (one bootstrap, one dividend feed,
 * three meeting statuses, and two endpoints per issue type), so `$timeout`
 * sits at 900s — comfortably inside the ordering the README requires:
 * job timeout 900 < pipeline supervisor 3660 < connection retry_after 3720,
 * which is what stops a slow run being started a second time.
 *
 * **No weekday guard, and no trading-day guard either.** The trading calendar
 * is observed rather than predicted, so nothing here may encode a weekday; and
 * unlike the price jobs this one has no reason to skip a closed market —
 * issuers publish book-close and AGM notices on days the market does not
 * trade, and a calendar that only updates on trading days would learn about a
 * Sunday announcement a day late. It is safe to run daily because it is
 * idempotent: rows are matched on (source, external_key) and updated in place.
 */
class ScrapeCorporateActionsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    /** One short retry; the next scheduled tick picks it up regardless. */
    public array $backoff = [180];

    public function uniqueId(): string
    {
        return 'scrape-corporate-actions';
    }

    public function uniqueFor(): int
    {
        return 900;
    }

    public function handle(SharesansarCorporateActionSource $source): void
    {
        $run = (new CorporateCalendarService($source))->scrape();

        Log::channel('scraper')->info('Corporate calendar job finished', [
            'run_id' => $run->id,
            'status' => $run->status,
            'published_on' => $run->trade_date?->toDateString(),
            'created' => $run->rows_created,
            'updated' => $run->rows_updated,
            'rejected' => $run->rows_rejected,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('scraper')->error('Corporate calendar crawl permanently failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
