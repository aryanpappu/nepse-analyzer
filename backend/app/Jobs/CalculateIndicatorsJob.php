<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Stock;
use App\Services\Indicators\IndicatorPersistenceService;
use App\Services\Playbook\PlaybookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recalculates technical indicators for every stock that traded recently.
 *
 * Runs in-process rather than fanning out one job per scrip: the maths is cheap
 * and 300+ tiny jobs would cost more in queue overhead than they save.
 */
class CalculateIndicatorsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 1800;

    public function __construct(
        public readonly ?string $tradeDate = null,
        public readonly ?string $symbol = null,
        public readonly bool $queueAnalysis = true,
    ) {}

    public function handle(IndicatorPersistenceService $indicators): void
    {
        $query = Stock::query()->active();

        if ($this->symbol !== null) {
            $query->symbol($this->symbol);
        }

        $processed = 0;
        $skipped = 0;
        $failed = 0;

        $query->chunkById(100, function ($stocks) use ($indicators, &$processed, &$skipped, &$failed): void {
            foreach ($stocks as $stock) {
                try {
                    // Only the recent tail changes on a normal daily run; a full
                    // rewrite of years of history per stock would be wasteful.
                    $written = $indicators->recalculate($stock, onlyLastDays: 10);

                    $written > 0 ? $processed++ : $skipped++;
                } catch (Throwable $e) {
                    $failed++;

                    Log::channel('analysis')->error('Indicator calculation failed', [
                        'symbol' => $stock->symbol,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        Log::channel('analysis')->info('Indicator calculation finished', [
            'processed' => $processed,
            'skipped_insufficient_history' => $skipped,
            'failed' => $failed,
        ]);

        if ($this->symbol === null) {
            // Rescore every profile before the AI analysis is queued, so a
            // signal and its playbook verdict always describe the same session.
            $playbook = app(PlaybookService::class)->rebuild();

            Log::channel('analysis')->info('Profile verdicts rebuilt', $playbook);
        }

        if ($this->queueAnalysis && $this->symbol === null) {
            QueueStockAnalysisJob::dispatch($this->tradeDate);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('analysis')->error('Indicator job permanently failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
