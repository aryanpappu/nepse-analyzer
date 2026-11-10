<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\InvestorProfile;
use App\Exceptions\ClaudeApiException;
use App\Http\Controllers\Concerns\AuthorizesPortfolioAccess;
use App\Http\Controllers\Controller;
use App\Models\BuyRecommendationRun;
use App\Models\Portfolio;
use App\Services\AI\BuyRecommendationService;
use App\Services\AI\LlmManager;
use App\Services\AI\PortfolioAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class PortfolioAnalysisController extends Controller
{
    use AuthorizesPortfolioAccess;

    public function __construct(
        private readonly PortfolioAnalyzer $analyzer,
        private readonly BuyRecommendationService $recommender,
    ) {}

    /**
     * Run (or re-run) the portfolio health analysis.
     *
     * Synchronous rather than queued: the user is waiting on the result, and a
     * single Opus call returns in seconds.
     */
    public function analyze(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        if (! $this->aiConfigured()) {
            return $this->notConfigured();
        }

        try {
            $analysis = $this->analyzer->analyze($portfolio);
        } catch (ClaudeApiException $e) {
            Log::channel('analysis')->warning('Portfolio AI request failed', ['error' => $e->getMessage(), 'portfolio_id' => $portfolio->id]);

            return response()->json(['message' => 'Analysis could not be completed. Check your portfolio data and try again later.'], 503);
        }

        return response()->json([
            'data' => [
                'analysis_date' => $analysis->analysis_date->toDateString(),
                'health_score' => $analysis->health_score,
                'diversification_grade' => $analysis->diversification_grade,
                'risk_level' => $analysis->risk_level,
                'total_invested' => (float) $analysis->total_invested,
                'total_value' => (float) $analysis->total_value,
                'unrealized_gain' => (float) $analysis->unrealized_gain,
                'unrealized_gain_percent' => (float) $analysis->unrealized_gain_percent,
                'summary' => $analysis->summary,
                'strengths' => $analysis->strengths,
                'weaknesses' => $analysis->weaknesses,
                'concentration_risks' => $analysis->concentration_risks,
                'rebalancing_actions' => $analysis->rebalancing_actions,
                'sector_allocation' => $analysis->sector_allocation,
                'model' => $analysis->model,
            ],
        ]);
    }

    /**
     * Ask Claude what to buy with the idle cash.
     */
    public function recommend(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        $profile = $this->profile($request);

        if (! $this->aiConfigured()) {
            return $this->notConfigured();
        }

        try {
            $run = $this->recommender->recommend($portfolio, null, $profile);
        } catch (ClaudeApiException $e) {
            Log::channel('analysis')->warning('Portfolio AI request failed', ['error' => $e->getMessage(), 'portfolio_id' => $portfolio->id]);

            return response()->json(['message' => 'Analysis could not be completed. Check your portfolio data and try again later.'], 503);
        }

        return response()->json(['data' => $this->runPayload($run)]);
    }

    /**
     * The most recent recommendation set, without spending an API call.
     */
    public function latestRecommendations(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        $profile = $this->profile($request);
        $run = BuyRecommendationRun::where('portfolio_id', $portfolio->id)
            ->where('investor_profile', $profile->value)
            ->with('recommendations.stock.sector')->orderByDesc('analysis_date')->orderByDesc('id')->first();

        if ($run === null) {
            return response()->json([
                'data' => null,
                'message' => 'No buy recommendations generated yet.',
            ]);
        }

        return response()->json(['data' => $this->runPayload($run)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function runPayload(BuyRecommendationRun $run): array
    {
        $run->loadMissing('recommendations.stock.sector');

        return [
            'analysis_date' => $run->analysis_date->toDateString(),
            'investor_profile' => $run->investor_profile,
            'investable_cash' => (float) $run->investable_cash,
            'allocated_amount' => (float) $run->allocated_amount,
            'reserve_amount' => (float) $run->reserve_amount,
            'strategy_summary' => $run->strategy_summary,
            'avoid' => $run->avoid_list,
            'model' => $run->model,
            'recommendations' => $run->recommendations->map(static fn ($recommendation): array => [
                'rank' => $recommendation->rank,
                'symbol' => $recommendation->stock->symbol,
                'name' => $recommendation->stock->name,
                'sector' => $recommendation->stock->sector?->name,
                'action' => $recommendation->action,
                'suggested_quantity' => (float) $recommendation->suggested_quantity,
                'suggested_price' => (float) $recommendation->suggested_price,
                'estimated_cost' => (float) $recommendation->estimated_cost,
                'allocation_percent' => $recommendation->allocation_percent === null
                    ? null : (float) $recommendation->allocation_percent,
                'stop_loss' => $recommendation->stop_loss === null ? null : (float) $recommendation->stop_loss,
                'target_price' => $recommendation->target_price === null ? null : (float) $recommendation->target_price,
                'conviction' => $recommendation->conviction,
                'risk_level' => $recommendation->risk_level,
                'time_horizon' => $recommendation->time_horizon,
                'reason' => $recommendation->reason,
            ])->all(),
        ];
    }

    private function profile(Request $request): InvestorProfile
    {
        $validated = $request->validate(['profile' => ['sometimes', Rule::enum(InvestorProfile::class)]]);

        return InvestorProfile::from($validated['profile'] ?? InvestorProfile::LongTerm->value);
    }

    private function aiConfigured(): bool
    {
        // Check the provider actually in use, not Anthropic specifically —
        // otherwise switching to the free driver reads as "not configured".
        return app(LlmManager::class)->isConfigured((string) config('ai.driver'));
    }

    private function notConfigured(): JsonResponse
    {
        return response()->json([
            'message' => 'AI analysis is currently unavailable. Please try again later.',
        ], 503);
    }
}
