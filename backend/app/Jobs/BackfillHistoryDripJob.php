<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Scraper\Backfill\HistoryBackfillService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drips one small, budgeted slice of the historical backfill.
 *
 * Instead of an operator remembering to run `nepse:backfill --days=1825` for
 * every scrip, this ticks on a schedule during hours the live pipeline is
 * idle, spends config('nepse.backfill.symbols_per_run') requests catching a
 * few symbols up towards config('nepse.backfill.target_years'), and leaves
 * the rest for the next tick. See HistoryBackfillService for the gap/holiday
 * decisions and App\Services\Scraper\Backfill\BackfillBudget for the pacing.
 */
class BackfillHistoryDripJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    /** A single short retry; the next scheduled tick will pick up regardless. */
    public array $backoff = [60];

    /**
     * Only one drip run in flight at a time - overlapping runs would double
     * up on the same request budget and defeat the whole point of pacing it.
     */
    public function uniqueId(): string
    {
        return 'backfill-history-drip';
    }

    public function uniqueFor(): int
    {
        return 300;
    }

    public function handle(HistoryBackfillService $backfill): void
    {
        $result = $backfill->runDrip();

        Log::channel('scraper')->info('Backfill drip finished', $result);
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('scraper')->error('Backfill drip permanently failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
