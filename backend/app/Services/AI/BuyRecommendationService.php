<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\InvestorProfile;
use App\Exceptions\ClaudeApiException;
use App\Models\BuyRecommendation;
use App\Models\BuyRecommendationRun;
use App\Models\Portfolio;
use App\Models\Stock;
use App\Models\StockSignal;
use App\Services\AI\Prompts\BuyRecommendationPrompt;
use App\Services\Portfolio\PortfolioValuationService;
use App\Services\Portfolio\TransactionCostCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns idle cash plus current signals into a concrete shopping list.
 *
 * The budget is enforced here, not trusted to the model: recommendations are
 * accepted in rank order until the cash runs out, and the estimated cost of
 * each line is recomputed from quantity x price rather than taken as given.
 */
final class BuyRecommendationService
{
    private const MAX_CANDIDATES = 40;

    public function __construct(
        private readonly LlmClient $claude,
        private readonly BuyRecommendationPrompt $prompt,
        private readonly PortfolioValuationService $valuation,
    ) {}

    /**
     * @throws ClaudeApiException
     */
    public function recommend(Portfolio $portfolio, ?Carbon $analysisDate = null, InvestorProfile $profile = InvestorProfile::LongTerm): BuyRecommendationRun
    {
        $analysisDate ??= Carbon::today();
        if (! $analysisDate->isToday()) {
            throw new ClaudeApiException('Buy recommendations require today’s portfolio and market data; historical requests are not supported.');
        }
        $investableCash = $portfolio->investableCash();

        if ($investableCash < 1000) {
            throw new ClaudeApiException(
                sprintf(
                    'Only NPR %s is available in "%s" — add cash or bank balance before requesting buy recommendations.',
                    number_format($investableCash, 2),
                    $portfolio->name,
                ),
                ['portfolio_id' => $portfolio->id, 'investable_cash' => $investableCash],
            );
        }

        $summary = $this->valuation->summarize($portfolio);
        $holdings = $this->valuation->holdingBreakdown($portfolio);
        $candidates = $this->candidates($investableCash, $profile);

        if ($candidates === []) {
            throw new ClaudeApiException(
                'No analysed candidates are available yet. Run the daily scrape and analysis first.',
                ['portfolio_id' => $portfolio->id],
            );
        }

        $result = $this->claude->structured(
            systemPrompt: $this->prompt->system(),
            userPrompt: $this->prompt->user($portfolio, $summary, $holdings, $candidates, $investableCash, $profile),
            toolName: 'record_buy_recommendations',
            toolDescription: 'Record which scrips to buy with the available cash, at what size, price, stop and target.',
            schema: $this->prompt->schema(),
            tier: LlmClient::TIER_DEEP,
        );

        return $this->persist($portfolio, $analysisDate, $investableCash, $result, array_column($candidates, 'symbol'), $profile);
    }

    /**
     * @param  array{data: array<string, mixed>, model: string, input_tokens: int, output_tokens: int, latency_ms: int}  $result
     * @param  list<string>  $eligibleSymbols
     */
    private function persist(
        Portfolio $portfolio,
        Carbon $analysisDate,
        float $investableCash,
        array $result,
        array $eligibleSymbols,
        InvestorProfile $profile = InvestorProfile::LongTerm,
    ): BuyRecommendationRun {
        $calculator = TransactionCostCalculator::fromConfig();
        $data = $result['data'];
        $lines = is_array($data['recommendations'] ?? null) ? $data['recommendations'] : [];

        // Resolve tickers to real stocks; a hallucinated symbol is dropped.
        $symbols = array_values(array_filter(array_map(
            static fn ($line): ?string => is_array($line) && is_string($line['symbol'] ?? null) ? strtoupper(trim($line['symbol'])) : null,
            $lines,
        )));

        $stocks = Stock::whereIn('symbol', array_intersect($symbols, $eligibleSymbols))->get()->keyBy('symbol');
        $seen = [];

        $accepted = [];
        $remaining = $investableCash;
        $rank = 1;

        foreach ($lines as $line) {
            if (! is_array($line) || ! is_string($line['symbol'] ?? null)) {
                continue;
            }

            $symbol = strtoupper(trim((string) $line['symbol']));
            $stock = $stocks->get($symbol);

            if ($stock === null || isset($seen[$symbol])) {
                Log::channel('analysis')->warning('Dropped a recommendation for an unknown symbol', [
                    'symbol' => $symbol,
                    'portfolio_id' => $portfolio->id,
                ]);

                continue;
            }

            $quantity = is_numeric($line['suggested_quantity'] ?? null)
                ? (int) floor((float) $line['suggested_quantity'] / 10) * 10 : 0;

            // A model-supplied quote is not a verifiable execution price.
            $price = round((float) $stock->last_price, 2);

            if ($quantity <= 0 || $price <= 0) {
                continue;
            }

            // Recompute cost — never trust the model's arithmetic for money.
            $costForQuantity = static function (int $units) use ($calculator, $price, $analysisDate, $stock): float {
                $fees = $calculator->compute(TransactionCostCalculator::SIDE_BUY, $units, $price, $analysisDate, $stock->instrument_type);

                return round($fees->turnover + $fees->total, 2);
            };
            $cost = $costForQuantity($quantity);

            if ($cost > $remaining) {
                // Scale the line down to what the budget actually allows,
                // rounded to the 10-share lot, instead of dropping it outright.
                $low = 0;
                $high = min((int) floor($remaining / ($price * 10)), (int) ($quantity / 10));
                while ($low < $high) {
                    $middle = (int) ceil(($low + $high) / 2);
                    if ($costForQuantity($middle * 10) <= $remaining) {
                        $low = $middle;
                    } else {
                        $high = $middle - 1;
                    }
                }
                $affordable = $low * 10;

                if ($affordable <= 0) {
                    Log::channel('analysis')->info('Skipped a recommendation that no longer fits the budget', [
                        'symbol' => $symbol,
                        'required' => $cost,
                        'remaining' => $remaining,
                    ]);

                    continue;
                }

                Log::channel('analysis')->info('Trimmed a recommendation to fit the budget', [
                    'symbol' => $symbol,
                    'from' => $quantity,
                    'to' => $affordable,
                ]);

                $quantity = $affordable;
                $cost = $costForQuantity($quantity);
            }

            $seen[$symbol] = true;
            $remaining = round($remaining - $cost, 2);
            $stop = $this->positive($line['stop_loss'] ?? null);
            $target = $this->positive($line['target_price'] ?? null);

            $accepted[] = [
                'stock_id' => $stock->id,
                'rank' => $rank++,
                'action' => in_array(($line['action'] ?? 'buy'), ['buy', 'add'], true)
                    ? (string) ($line['action'] ?? 'buy')
                    : 'buy',
                'suggested_quantity' => $quantity,
                'suggested_price' => $price,
                'estimated_cost' => $cost,
                'allocation_percent' => $investableCash > 0 ? round($cost / $investableCash * 100, 2) : null,
                'stop_loss' => $stop !== null && $stop < $price && ($price - $stop) / $price <= $profile->maxStopLoss() ? $stop : null,
                'target_price' => $target !== null && $target > $price ? $target : null,
                'conviction' => isset($line['conviction']) && is_numeric($line['conviction'])
                    ? max(0, min(100, (int) $line['conviction']))
                    : null,
                'risk_level' => $this->enumOrNull($line['risk_level'] ?? null, ['low', 'medium', 'high']),
                'time_horizon' => $profile->timeHorizon()->value,
                'reason' => isset($line['reason']) ? (string) $line['reason'] : null,
            ];
        }

        $allocated = round(array_sum(array_column($accepted, 'estimated_cost')), 2);

        return DB::transaction(function () use (
            $portfolio, $analysisDate, $investableCash, $allocated, $accepted, $data, $result, $profile
        ): BuyRecommendationRun {
            $run = BuyRecommendationRun::updateOrCreate(
                ['portfolio_id' => $portfolio->id, 'analysis_date' => $analysisDate->toDateString(), 'investor_profile' => $profile->value],
                [
                    'investable_cash' => $investableCash,
                    'allocated_amount' => $allocated,
                    // Reserve is whatever is genuinely left, so the three
                    // figures always reconcile on screen.
                    'reserve_amount' => round($investableCash - $allocated, 2),
                    'strategy_summary' => isset($data['strategy_summary']) ? (string) $data['strategy_summary'] : null,
                    'avoid_list' => $data['avoid'] ?? null,
                    'model' => $result['model'],
                    'input_tokens' => $result['input_tokens'],
                    'output_tokens' => $result['output_tokens'],
                ],
            );

            // Replace rather than append, so re-running on the same day does
            // not stack duplicate lines.
            $run->recommendations()->delete();

            foreach ($accepted as $line) {
                BuyRecommendation::create(array_merge($line, ['run_id' => $run->id]));
            }

            Log::channel('analysis')->info('Buy recommendations recorded', [
                'portfolio_id' => $portfolio->id,
                'lines' => count($accepted),
                'allocated' => $allocated,
                'investable' => $investableCash,
            ]);

            return $run->load('recommendations.stock');
        });
    }

    /**
     * Build the candidate universe: liquid, actively analysed scrips with a
     * current buy or hold signal.
     *
     * @return list<array<string, mixed>>
     */
    private function candidates(float $investableCash, InvestorProfile $profile = InvestorProfile::LongTerm): array
    {
        $ttlDays = (int) config('nepse.analysis.signal_ttl_days', 3);

        $stocks = Stock::query()
            ->active()
            ->where('instrument_type', 'equity')
            ->whereNotNull('last_price')
            ->where('last_price', '>', 0)
            ->whereDate('last_traded_on', '>=', Carbon::today()->subDays($ttlDays)->toDateString())
            ->whereDate('last_traded_on', '<=', Carbon::today()->toDateString())
            // Cannot buy even one lot — not a useful suggestion.
            ->where('last_price', '<=', max($investableCash / 10, 1))
            ->with(['sector', 'latestIndicator', 'latestFundamental', 'signals' => function ($query) use ($profile): void {
                $query->where('investor_profile', $profile->value)->orderByDesc('analysis_date')->orderByDesc('id')->limit(1);
            }])
            ->whereHas('signals', function ($query) use ($ttlDays, $profile): void {
                $query->where('investor_profile', $profile->value)->where('analysis_date', '>=', Carbon::today()->subDays($ttlDays)->toDateString())
                    ->whereIn('signal', ['buy', 'hold']);
            })
            ->get();

        // Average turnover over the last 20 sessions, as a liquidity gate.
        $turnovers = DB::table('stock_prices')
            ->select('stock_id', DB::raw('AVG(turnover) as avg_turnover'))
            ->whereIn('stock_id', $stocks->pluck('id'))
            ->where('trade_date', '>=', Carbon::today()->subDays(40)->toDateString())
            ->groupBy('stock_id')
            ->pluck('avg_turnover', 'stock_id');

        $candidates = [];

        foreach ($stocks as $stock) {
            $signal = $stock->signals->first();

            if (! $signal instanceof StockSignal || $signal->isStale()
                || Carbon::parse((string) $signal->getRawOriginal('analysis_date'))->gt(Carbon::today())
                || ! in_array((string) $signal->getRawOriginal('signal'), ['buy', 'hold'], true)
                || (float) ($turnovers[$stock->id] ?? 0) < (float) config('nepse.analysis.min_turnover', 100000)) {
                continue;
            }

            $candidates[] = [
                'symbol' => $stock->symbol,
                'name' => $stock->name,
                'sector' => $stock->sector?->name,
                'last_price' => (float) $stock->last_price,
                'signal' => (string) $signal->getRawOriginal('signal'),
                'confidence' => $signal->confidence,
                'risk_level' => (string) $signal->getRawOriginal('risk_level'),
                'rsi' => $stock->latestIndicator?->rsi_14,
                'trend' => $stock->latestIndicator?->trend,
                'pe_ratio' => $stock->latestFundamental?->pe_ratio,
                'avg_turnover' => isset($turnovers[$stock->id]) ? (float) $turnovers[$stock->id] : null,
                '_score' => ((string) $signal->getRawOriginal('signal') === 'buy' ? 1000 : 0) + $signal->confidence,
            ];
        }

        // Strongest signals first, then trim — the prompt stays a sane size and
        // the model is not asked to rank 300 near-identical rows.
        usort($candidates, static fn (array $a, array $b): int => $b['_score'] <=> $a['_score']);

        return array_map(
            static function (array $candidate): array {
                unset($candidate['_score']);

                return $candidate;
            },
            array_slice($candidates, 0, self::MAX_CANDIDATES),
        );
    }

    private function positive(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value > 0 ? round((float) $value, 2) : null;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function enumOrNull(mixed $value, array $allowed): ?string
    {
        $normalized = strtolower(str_replace([' ', '-'], '_', trim((string) $value)));

        return in_array($normalized, $allowed, true) ? $normalized : null;
    }
}
