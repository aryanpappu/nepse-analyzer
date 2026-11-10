<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\ClaudeApiException;
use App\Models\Portfolio;
use App\Models\PortfolioAnalysis;
use App\Services\AI\Prompts\PortfolioAnalysisPrompt;
use App\Services\Portfolio\PortfolioValuationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

final class PortfolioAnalyzer
{
    public function __construct(
        private readonly LlmClient $claude,
        private readonly PortfolioAnalysisPrompt $prompt,
        private readonly PortfolioValuationService $valuation,
    ) {}

    /**
     * @throws ClaudeApiException
     */
    public function analyze(Portfolio $portfolio, ?Carbon $analysisDate = null): PortfolioAnalysis
    {
        $analysisDate ??= Carbon::today();

        $summary = $this->valuation->summarize($portfolio);
        $holdings = $this->valuation->holdingBreakdown($portfolio);

        if ($holdings === []) {
            throw new ClaudeApiException(
                "Portfolio \"{$portfolio->name}\" has no open positions to analyse.",
                ['portfolio_id' => $portfolio->id],
            );
        }

        $result = $this->claude->structured(
            systemPrompt: $this->prompt->system(),
            userPrompt: $this->prompt->user($portfolio, $summary, $holdings),
            toolName: 'record_portfolio_analysis',
            toolDescription: 'Record the health assessment, concentration risks and rebalancing plan for this portfolio.',
            schema: $this->prompt->schema(),
            // Portfolio-level reasoning is low volume and benefits from the
            // stronger model.
            tier: LlmClient::TIER_DEEP,
        );

        $data = $result['data'];

        $analysis = PortfolioAnalysis::updateOrCreate(
            ['portfolio_id' => $portfolio->id, 'analysis_date' => $analysisDate->toDateString()],
            [
                'health_score' => max(0, min(100, (int) ($data['health_score'] ?? 50))),
                'diversification_grade' => $this->grade($data['diversification_grade'] ?? null),
                'risk_level' => $this->riskLevel($data['risk_level'] ?? null),
                'total_invested' => $summary['total_invested'],
                'total_value' => $summary['total_value'],
                'unrealized_gain' => $summary['unrealized_gain'],
                'unrealized_gain_percent' => $summary['unrealized_gain_percent'],
                // Store our own computed allocation, not the model's restatement
                // of it — the numbers on screen must come from the ledger.
                'sector_allocation' => $summary['sector_allocation'],
                'concentration_risks' => $data['concentration_risks'] ?? null,
                'rebalancing_actions' => $this->filterActions($data['rebalancing_actions'] ?? [], $holdings),
                'strengths' => $data['strengths'] ?? null,
                'weaknesses' => $data['weaknesses'] ?? null,
                'summary' => isset($data['summary']) ? (string) $data['summary'] : null,
                'model' => $result['model'],
                'input_tokens' => $result['input_tokens'],
                'output_tokens' => $result['output_tokens'],
            ],
        );

        Log::channel('analysis')->info('Portfolio analysis recorded', [
            'portfolio_id' => $portfolio->id,
            'health_score' => $analysis->health_score,
            'model' => $result['model'],
        ]);

        return $analysis;
    }

    /**
     * Drop any sell/trim action naming a scrip the portfolio does not hold —
     * acting on one would be impossible, and showing it erodes trust.
     *
     * @param  array<int, mixed>  $actions
     * @param  list<array<string, mixed>>  $holdings
     * @return list<array<string, mixed>>
     */
    private function filterActions(array $actions, array $holdings): array
    {
        $owned = array_flip(array_column($holdings, 'symbol'));
        $filtered = [];

        foreach ($actions as $action) {
            if (! is_array($action) || ! isset($action['action'], $action['symbol'])) {
                continue;
            }

            $isExit = in_array(strtolower((string) $action['action']), ['sell', 'trim'], true);

            if ($isExit && ! isset($owned[strtoupper((string) $action['symbol'])])) {
                Log::channel('analysis')->warning('Dropped a rebalancing action for an unheld scrip', [
                    'symbol' => $action['symbol'],
                    'action' => $action['action'],
                ]);

                continue;
            }

            $filtered[] = $action;
        }

        return $filtered;
    }

    private function grade(mixed $value): ?string
    {
        $grade = strtoupper(trim((string) $value));

        return in_array($grade, ['A', 'B', 'C', 'D', 'F'], true) ? $grade : null;
    }

    private function riskLevel(mixed $value): string
    {
        $level = strtolower(trim((string) $value));

        return in_array($level, ['low', 'medium', 'high'], true) ? $level : 'medium';
    }
}
