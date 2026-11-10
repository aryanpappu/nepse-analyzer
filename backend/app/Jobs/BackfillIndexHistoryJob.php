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
 * Periodic top-up of historical index bars (NEPSE Index, Sensitive Index, the
 * sector sub-indices) via ShareSansar's index-history feed.
 *
 * Unlike {@see BackfillHistoryDripJob} this is not a small budgeted slice of a
 * much larger unknown — there are ~18 indices total (a fixed vocabulary, see
 * App\Services\Scraper\IndexCatalog), not ~300 scrips with unknown gaps, so
 * one bounded pass per tick comfortably keeps up. A small `top_up_days`
 * window (config('nepse.index_backfill.top_up_days')) is enough once the
 * first full `nepse:indices --backfill` has run — this job exists to catch up
 * whatever sessions happened since the last run, not to re-fetch years of
 * already-stored history every time.
 */
class BackfillIndexHistoryJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    /** A single short retry; the next scheduled tick will pick up regardless. */
    public array $backoff = [120];

    public function uniqueId(): string
    {
        return 'backfill-index-history';
    }

    public function uniqueFor(): int
    {
        return 900;
    }

    public function handle(NepseScraperService $scraper): void
    {
        $days = (int) config('nepse.index_backfill.top_up_days');

        $run = $scraper->backfillIndexHistory(null, $days);

        Log::channel('scraper')->info('Index history top-up finished', [
            'run_id' => $run->id,
            'status' => $run->status,
            'created' => $run->rows_created,
            'updated' => $run->rows_updated,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('scraper')->error('Index history top-up permanently failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
