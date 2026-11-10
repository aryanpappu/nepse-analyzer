<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\InvestorProfile;
use App\Models\Portfolio;
use App\Services\AI\BuyRecommendationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateBuyRecommendationsJob implements ShouldBeUnique, ShouldQueue
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

    public InvestorProfile $profile = InvestorProfile::LongTerm;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly int $portfolioId,
        public readonly ?string $analysisDate = null,
        InvestorProfile $profile = InvestorProfile::LongTerm,
    ) {
        // See the note on AI_QUEUE_CONNECTION below. Set here rather than as a
        // property default, which would clash with the Queueable trait's own
        // $connection declaration.
        $this->profile = $profile;
        $this->onConnection(self::AI_QUEUE_CONNECTION);
    }

    public function uniqueId(): string
    {
        return 'buy-recommendations:'.$this->portfolioId.':'.($this->analysisDate ?? 'today').':'.$this->profile->value;
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function handle(BuyRecommendationService $service): void
    {
        $portfolio = Portfolio::find($this->portfolioId);

        if ($portfolio === null) {
            Log::channel('analysis')->warning('Buy recommendations skipped, portfolio not found', [
                'portfolio_id' => $this->portfolioId,
            ]);

            return;
        }

        $date = $this->analysisDate !== null ? Carbon::parse($this->analysisDate) : Carbon::today();

        $service->recommend($portfolio, $date, $this->profile);

        Cache::forget("portfolio:recommendations:{$portfolio->id}");
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('analysis')->error('Buy recommendation generation permanently failed', [
            'portfolio_id' => $this->portfolioId,
            'error' => $exception->getMessage(),
        ]);
    }
}
