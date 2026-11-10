<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Stock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Decides which stocks are worth spending a Claude call on today, and queues
 * one AnalyzeStockJob for each.
 *
 * The filtering here is the main cost control in the whole pipeline: analysing
 * all 300+ listed scrips daily, most of which barely trade, would be expensive
 * and would produce analysis nobody can act on.
 */
class QueueStockAnalysisJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly ?string $tradeDate = null,
        public readonly ?int $limit = null,
    ) {}

    public function handle(): void
    {
        $tradeDate = $this->tradeDate !== null
            ? Carbon::parse($this->tradeDate)
            : Carbon::today();

        $minTurnover = (float) config('nepse.analysis.min_turnover');
        $minHistory = (int) config('nepse.analysis.min_history_days');
        $batchSize = max(1, (int) config('nepse.analysis.batch_size'));

        $stocks = Stock::query()
            ->active()
            ->where('instrument_type', 'equity')
            // Traded on the target session with enough turnover to matter.
            ->whereHas('prices', function ($query) use ($tradeDate, $minTurnover): void {
                $query->where('trade_date', $tradeDate->toDateString())
                    ->where('turnover', '>=', $minTurnover);
            })
            // Enough history for the indicators to mean anything.
            ->has('prices', '>=', $minHistory)
            // Skip anything already analysed today — makes the job re-runnable.
            ->whereDoesntHave('signals', function ($query) use ($tradeDate): void {
                $query->where('analysis_date', $tradeDate->toDateString());
            })
            ->orderByDesc('market_capitalization')
            ->limit($this->limit ?? 10000)
            ->get(['id', 'symbol']);

        if ($stocks->isEmpty()) {
            Log::channel('analysis')->info('No stocks qualified for analysis', [
                'trade_date' => $tradeDate->toDateString(),
                'min_turnover' => $minTurnover,
            ]);

            return;
        }

        // Stagger dispatch so a burst of API calls does not trip rate limits.
        // Each batch starts a minute after the previous one.
        $delaySeconds = 0;

        foreach ($stocks->chunk($batchSize) as $chunk) {
            foreach ($chunk as $stock) {
                AnalyzeStockJob::dispatch($stock->symbol, $tradeDate->toDateString())
                    ->delay(now()->addSeconds($delaySeconds));
            }

            $delaySeconds += 60;
        }

        Log::channel('analysis')->info('Queued stock analysis', [
            'trade_date' => $tradeDate->toDateString(),
            'stocks' => $stocks->count(),
            'batches' => (int) ceil($stocks->count() / $batchSize),
        ]);
    }
}
