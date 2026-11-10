<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\InvestorProfile;
use App\Exceptions\ClaudeApiException;
use App\Models\Stock;
use App\Services\AI\StockSignalAnalyzer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeStockJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /*
     * Model calls run on their own connection, whose default queue Horizon
     * works with a single process: the provider pacing in GeminiClient is
     * per-process, so a second worker would double the real request rate. The
     * queue name is left to the connection so REDIS_QUEUE_AI stays the one
     * place it is configured.
     */
    private const AI_QUEUE_CONNECTION = 'redis-ai';

    public int $tries = 3;

    public int $timeout = 180;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly string $symbol,
        public readonly ?string $analysisDate = null,
        public readonly ?string $investorProfile = null,
    ) {
        // See the note on AI_QUEUE_CONNECTION below. Set here rather than as a
        // property default, which would clash with the Queueable trait's own
        // $connection declaration.
        $this->onConnection(self::AI_QUEUE_CONNECTION);
    }

    public function uniqueId(): string
    {
        return 'analyze-stock:'.strtoupper($this->symbol)
            .':'.($this->analysisDate ?? 'today')
            .':'.($this->investorProfile ?? 'default');
    }

    public function uniqueFor(): int
    {
        return 7200;
    }

    public function handle(StockSignalAnalyzer $analyzer): void
    {
        $stock = Stock::symbol($this->symbol)->first();

        if ($stock === null) {
            Log::channel('analysis')->warning('Analysis skipped, unknown symbol', ['symbol' => $this->symbol]);

            return;
        }

        $date = $this->analysisDate !== null ? Carbon::parse($this->analysisDate) : Carbon::today();

        try {
            $signal = $analyzer->analyze(
                $stock,
                $date,
                $this->investorProfile === null
                    ? null
                    : InvestorProfile::tryFrom($this->investorProfile),
            );

            // Drop cached reads so the API serves the new signal immediately.
            Cache::forget("stock:detail:{$stock->symbol}");
            Cache::forget('signals:latest');
        } catch (ClaudeApiException $e) {
            // A spend-limit stop is a deliberate decision, not a failure —
            // releasing the job would just retry into the same wall.
            if ($e->isBudgetExhausted()) {
                Log::channel('analysis')->warning('Analysis stopped by the daily spend guard', [
                    'symbol' => $this->symbol,
                ]);

                $this->fail($e);

                return;
            }

            throw $e;
        }

        Log::channel('analysis')->debug('Stock analysed', [
            'symbol' => $stock->symbol,
            'signal' => $signal->signal->value,
            'confidence' => $signal->confidence,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('analysis')->error('Stock analysis permanently failed', [
            'symbol' => $this->symbol,
            'error' => $exception->getMessage(),
        ]);
    }
}
