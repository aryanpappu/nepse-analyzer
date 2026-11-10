<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\FundamentalAnalysis;
use App\Models\Stock;
use App\Models\StockFundamental;
use App\Services\AI\Prompts\FundamentalAnalysisPrompt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class FundamentalAnalyzer
{
    public function __construct(
        private readonly LlmClient $claude,
        private readonly FundamentalAnalysisPrompt $prompt,
    ) {}

    public function analyze(Stock $stock, ?Carbon $analysisDate = null): FundamentalAnalysis
    {
        $analysisDate ??= Carbon::today();

        $fundamental = $stock->latestFundamental()->first();
        $sectorContext = $this->sectorContext($stock);

        $result = $this->claude->structured(
            systemPrompt: $this->prompt->system(),
            userPrompt: $this->prompt->user($stock, $fundamental, $sectorContext),
            toolName: 'record_fundamental_analysis',
            toolDescription: 'Record the valuation verdict, quality scores and fair value range for this company.',
            schema: $this->prompt->schema(),
            tier: LlmClient::TIER_DEEP,
        );

        $data = $result['data'];

        [$fairLow, $fairHigh] = $this->fairValueRange($data, $stock);

        $analysis = FundamentalAnalysis::updateOrCreate(
            ['stock_id' => $stock->id, 'analysis_date' => $analysisDate->toDateString()],
            [
                'valuation' => $this->valuation($data['valuation'] ?? null),
                'quality_score' => $this->score($data['quality_score'] ?? null),
                'financial_health_score' => $this->score($data['financial_health_score'] ?? null),
                'dividend_sustainability_score' => $this->score($data['dividend_sustainability_score'] ?? null),
                'fair_value_low' => $fairLow,
                'fair_value_high' => $fairHigh,
                'sector_pe_comparison' => is_numeric($data['sector_pe_comparison'] ?? null)
                    ? round((float) $data['sector_pe_comparison'], 4)
                    : null,
                'strengths' => $data['strengths'] ?? null,
                'concerns' => $data['concerns'] ?? null,
                'metrics_snapshot' => array_filter([
                    'fundamental' => $fundamental?->only([
                        'fiscal_period', 'eps', 'pe_ratio', 'book_value', 'pb_ratio',
                        'dividend_percent', 'dividend_yield', 'roe',
                    ]),
                    'sector_context' => $sectorContext,
                    'dividend_assessment' => $data['dividend_assessment'] ?? null,
                    'data_gaps' => $data['data_gaps'] ?? null,
                ]),
                'summary' => isset($data['summary']) ? (string) $data['summary'] : null,
                'model' => $result['model'],
                'input_tokens' => $result['input_tokens'],
                'output_tokens' => $result['output_tokens'],
            ],
        );

        Log::channel('analysis')->info('Fundamental analysis recorded', [
            'symbol' => $stock->symbol,
            'valuation' => $analysis->valuation,
            'model' => $result['model'],
        ]);

        return $analysis;
    }

    /**
     * Sector medians, so the model compares against peers rather than a
     * remembered global benchmark.
     *
     * @return array<string, mixed>
     */
    private function sectorContext(Stock $stock): array
    {
        if ($stock->sector_id === null) {
            return [];
        }

        // Latest fundamental row per peer in the same sector.
        $latestIds = StockFundamental::query()
            ->select(DB::raw('MAX(stock_fundamentals.id) as id'))
            ->join('stocks', 'stocks.id', '=', 'stock_fundamentals.stock_id')
            ->where('stocks.sector_id', $stock->sector_id)
            ->where('stocks.is_active', true)
            ->groupBy('stock_fundamentals.stock_id')
            ->pluck('id');

        if ($latestIds->isEmpty()) {
            return [];
        }

        $peers = StockFundamental::whereIn('id', $latestIds)->get();

        $context = ['peer_count' => $peers->count()];

        foreach (['pe_ratio' => 'median_pe', 'pb_ratio' => 'median_pb', 'roe' => 'median_roe'] as $field => $key) {
            $values = $peers->pluck($field)
                ->filter(static fn ($value): bool => $value !== null && (float) $value > 0)
                ->map(static fn ($value): float => (float) $value)
                ->sort()
                ->values();

            if ($values->isNotEmpty()) {
                $context[$key] = round($this->median($values->all()), 4);
            }
        }

        return $context;
    }

    /**
     * @param  list<float>  $values  Must be sorted ascending.
     */
    private function median(array $values): float
    {
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 0
            ? ($values[$middle - 1] + $values[$middle]) / 2
            : $values[$middle];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?float, 1: ?float}
     */
    private function fairValueRange(array $data, Stock $stock): array
    {
        $low = is_numeric($data['fair_value_low'] ?? null) ? round((float) $data['fair_value_low'], 2) : null;
        $high = is_numeric($data['fair_value_high'] ?? null) ? round((float) $data['fair_value_high'], 2) : null;

        if ($low !== null && $high !== null && $low > $high) {
            [$low, $high] = [$high, $low];
        }

        // A fair value wildly detached from the traded price is a unit error,
        // not an insight. Drop rather than display it.
        $price = $stock->last_price === null ? null : (float) $stock->last_price;

        if ($price !== null && $price > 0) {
            foreach ([&$low, &$high] as &$value) {
                if ($value !== null && ($value > $price * 10 || $value < $price / 10)) {
                    Log::channel('analysis')->warning('Discarded implausible fair value', [
                        'symbol' => $stock->symbol,
                        'value' => $value,
                        'price' => $price,
                    ]);
                    $value = null;
                }
            }
            unset($value);
        }

        return [$low, $high];
    }

    private function valuation(mixed $value): ?string
    {
        $normalized = strtolower(str_replace([' ', '-'], '_', trim((string) $value)));

        return in_array($normalized, ['undervalued', 'fairly_valued', 'overvalued'], true)
            ? $normalized
            : null;
    }

    private function score(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) $value));
    }
}
