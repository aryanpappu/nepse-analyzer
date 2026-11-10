<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\InvestorProfile;
use App\Enums\RiskLevel;
use App\Enums\SignalType;
use App\Enums\TimeHorizon;
use App\Exceptions\ClaudeApiException;
use App\Models\FundNav;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\StockSignal;
use App\Services\AI\Prompts\MutualFundSignalPrompt;
use App\Services\AI\Prompts\StockSignalPrompt;
use App\Services\Analysis\FundamentalSnapshot;
use App\Services\Analysis\ScripComparator;
use App\Services\Scraper\InstrumentClassifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

final class StockSignalAnalyzer
{
    public function __construct(
        private readonly LlmClient $claude,
        private readonly StockSignalPrompt $prompt,
        private readonly MutualFundSignalPrompt $fundPrompt,
        private readonly InstrumentClassifier $classifier,
        private readonly ScripComparator $comparator,
        private readonly SignalPlanValidator $planValidator,
    ) {}

    /**
     * Produce and persist a trading signal for one stock.
     *
     * @throws ClaudeApiException
     */
    public function analyze(
        Stock $stock,
        ?Carbon $analysisDate = null,
        ?InvestorProfile $profile = null,
    ): StockSignal {
        $analysisDate ??= Carbon::today();
        $profile ??= InvestorProfile::default();

        // Today's stock metadata and peer valuations cannot reconstruct what
        // was known on an earlier date. Use the point-in-time backtest instead.
        if (! $analysisDate->isToday()) {
            throw new ClaudeApiException('AI signals support the current date only; use the historical playbook backtest for past dates.');
        }

        $maxAge = max(1, (int) config('nepse.analysis.max_price_age_days', 7));
        if ($stock->last_traded_on === null || Carbon::parse($stock->getRawOriginal('last_traded_on'))->lt(Carbon::today()->subDays($maxAge))) {
            throw new ClaudeApiException("Price data for {$stock->symbol} is stale or missing; refresh it before analysis.");
        }

        $instrumentType = (string) $stock->instrument_type;

        // Debentures have a fixed coupon and an UNKNOWN scrip would be analysed
        // with the wrong model entirely — refuse rather than produce something
        // confident and meaningless.
        if (! $this->classifier->isAnalysable($instrumentType)) {
            throw new ClaudeApiException(
                sprintf(
                    '%s is classified as "%s", which this pipeline does not analyse. %s',
                    $stock->symbol,
                    $instrumentType,
                    $instrumentType === InstrumentClassifier::UNKNOWN
                        ? 'Classify it first: php artisan nepse:classify --symbol='.$stock->symbol.' --set=equity'
                        : 'Only equities and mutual funds are analysed.',
                ),
                ['symbol' => $stock->symbol, 'instrument_type' => $instrumentType],
            );
        }

        // Fund units are valued on NAV, not on chart momentum, so they take a
        // completely different prompt.
        if ($this->classifier->usesNav($instrumentType)) {
            return $this->analyzeFund($stock, $analysisDate, $profile);
        }

        $history = $stock->prices()
            ->whereDate('trade_date', '<=', Carbon::today()->toDateString())
            ->orderByDesc('trade_date')
            ->limit(max(120, (int) config('nepse.analysis.prompt_history_days', 60)))
            ->get()
            ->sortBy('trade_date')
            ->values();

        $minHistory = (int) config('nepse.analysis.min_history_days', 30);

        if ($history->count() < $minHistory) {
            throw new ClaudeApiException(
                "Not enough price history for {$stock->symbol}: {$history->count()} sessions, need {$minHistory}.",
                ['symbol' => $stock->symbol],
            );
        }

        $indicator = $stock->latestIndicator()->first();
        $fundamental = $stock->latestFundamental()->first();
        $latest = $history->last();
        if (! $latest instanceof StockPrice || (float) $latest->close <= 0
            || Carbon::parse($latest->getRawOriginal('trade_date'))->lt(Carbon::today()->subDays($maxAge))) {
            throw new ClaudeApiException("Recent valid price history is missing for {$stock->symbol}.");
        }

        // Relative valuation. Empty when the sector is too thin to rank against,
        // in which case the prompt simply omits the section rather than
        // presenting a percentile computed from two peers.
        $stock->loadMissing(FundamentalSnapshot::relationsToLoad());
        $sectorPercentiles = $this->comparator->sectorPercentiles($stock);

        $result = $this->claude->structured(
            systemPrompt: $this->prompt->system(),
            userPrompt: $this->prompt->user(
                $stock, $history, $indicator, $fundamental, $sectorPercentiles, $profile,
            ),
            toolName: 'record_stock_signal',
            toolDescription: 'Record the trading signal, price levels and rationale for the analysed NEPSE stock.',
            schema: $this->prompt->schema(),
        );

        $data = $result['data'];
        $referencePrice = (float) $latest->close;

        $signal = SignalType::fromLoose($data['signal'] ?? null) ?? SignalType::Hold;

        $levels = $this->planValidator->validate($data, $signal, $referencePrice, $stock->symbol);

        $attributes = [
            'investor_profile' => $profile->value,
            'signal' => $signal->value,
            'confidence' => $this->clampInt($data['confidence'] ?? 50, 0, 100),
            'risk_level' => RiskLevel::fromLoose($data['risk_level'] ?? null)->value,
            // fromLoose() never returns null, so the fallback has to be chosen
            // on whether the model supplied a horizon at all. When it did not,
            // the profile's own horizon is the right answer — a swing analysis
            // that came back silent is still a swing horizon.
            'time_horizon' => isset($data['time_horizon'])
                ? TimeHorizon::fromLoose((string) $data['time_horizon'])->value
                : $profile->timeHorizon()->value,
            'reference_price' => $referencePrice,
            'entry_price' => $levels['entry_price'],
            'entry_price_max' => $levels['entry_price_max'],
            'exit_price' => $levels['exit_price'],
            'stop_loss' => $levels['stop_loss'],
            'target_1' => $levels['target_1'],
            'target_2' => $levels['target_2'],
            'risk_reward_ratio' => $levels['risk_reward_ratio'],
            'expected_return_percent' => $this->clampFloat($data['expected_return_percent'] ?? null, -100, 1000),
            'summary' => isset($data['summary']) ? (string) $data['summary'] : null,
            'rationale' => $data['rationale'] ?? null,
            'indicators_snapshot' => $indicator?->only([
                'trade_date',
                'rsi_14', 'macd', 'macd_signal', 'macd_histogram',
                'sma_20', 'sma_50', 'sma_200', 'atr_14', 'trend', 'trend_strength',
            ]),
            'model' => $result['model'],
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'latency_ms' => $result['latency_ms'],
        ];

        $stockSignal = StockSignal::updateOrCreate(
            [
                'stock_id' => $stock->id,
                'analysis_date' => $analysisDate->toDateString(),
                'investor_profile' => $profile->value,
            ],
            $attributes,
        );

        Log::channel('analysis')->info('Signal recorded', [
            'symbol' => $stock->symbol,
            'profile' => $profile->value,
            'signal' => $signal->value,
            'confidence' => $attributes['confidence'],
            'model' => $result['model'],
            'tokens' => $result['input_tokens'] + $result['output_tokens'],
        ]);

        return $stockSignal;
    }

    /**
     * Signal for a closed-end mutual fund, reasoned from NAV and the
     * premium/discount rather than from technical indicators.
     *
     * @throws ClaudeApiException
     */
    private function analyzeFund(Stock $fund, Carbon $analysisDate, InvestorProfile $profile): StockSignal
    {
        $navHistory = FundNav::where('stock_id', $fund->id)
            ->whereDate('nav_date', '<=', Carbon::today()->toDateString())
            ->orderBy('nav_date')
            ->get();

        $marketPrice = $fund->last_price === null ? null : (float) $fund->last_price;

        if ($marketPrice === null || ! is_finite($marketPrice) || $marketPrice <= 0) {
            throw new ClaudeApiException("No valid traded price for {$fund->symbol}; NAV alone is not an executable entry price.");
        }

        if ($navHistory->isEmpty()) {
            throw new ClaudeApiException(
                "NAV history for {$fund->symbol} is missing; refresh fund data before analysis.",
                ['symbol' => $fund->symbol],
            );
        }

        $latestNav = $navHistory->last();
        $maxNavAge = max(1, (int) config('nepse.analysis.max_nav_age_days', 45));
        if (Carbon::parse($latestNav->getRawOriginal('nav_date'))->lt(Carbon::today()->subDays($maxNavAge)) || $latestNav->nav <= 0) {
            throw new ClaudeApiException("NAV data for {$fund->symbol} is stale or invalid; refresh it before analysis.");
        }

        $result = $this->claude->structured(
            systemPrompt: $this->fundPrompt->system(),
            userPrompt: $this->fundPrompt->user($fund, $navHistory, $marketPrice)
                ."\n\nInvestor profile: ".$profile->label().". Use this profile's time horizon and risk tolerance. For this request the horizon is ".$profile->timeHorizon()->value.'.',
            toolName: 'record_stock_signal',
            toolDescription: 'Record the signal, price levels and rationale for the analysed mutual fund.',
            schema: $this->prompt->schema(),
        );

        $data = $result['data'];
        // Use the traded quote for entry geometry; NAV is valuation context.
        $referencePrice = $marketPrice;

        $signal = SignalType::fromLoose($data['signal'] ?? null) ?? SignalType::Hold;
        $levels = $this->planValidator->validate($data, $signal, $referencePrice, $fund->symbol);

        $stockSignal = StockSignal::updateOrCreate(
            ['stock_id' => $fund->id, 'analysis_date' => $analysisDate->toDateString(), 'investor_profile' => $profile->value],
            [
                'signal' => $signal->value,
                'confidence' => $this->clampInt($data['confidence'] ?? 50, 0, 100),
                'risk_level' => RiskLevel::fromLoose($data['risk_level'] ?? null)->value,
                'time_horizon' => isset($data['time_horizon'])
                    ? TimeHorizon::fromLoose($data['time_horizon'])->value
                    : $profile->timeHorizon()->value,
                'reference_price' => $referencePrice,
                'entry_price' => $levels['entry_price'],
                'entry_price_max' => $levels['entry_price_max'],
                'exit_price' => $levels['exit_price'],
                'stop_loss' => $levels['stop_loss'],
                'target_1' => $levels['target_1'],
                'target_2' => $levels['target_2'],
                'risk_reward_ratio' => $levels['risk_reward_ratio'],
                'expected_return_percent' => $this->clampFloat($data['expected_return_percent'] ?? null, -100, 1000),
                'summary' => isset($data['summary']) ? (string) $data['summary'] : null,
                'rationale' => $data['rationale'] ?? null,
                // NAV context stands in for the technical snapshot, so the UI
                // shows what actually drove a fund's call.
                'indicators_snapshot' => [
                    'instrument' => 'mutual_fund',
                    'nav' => $latestNav->nav,
                    'nav_date' => $latestNav->nav_date->toDateString(),
                    'market_price' => $marketPrice,
                    'premium_discount_percent' => $latestNav->premium_discount_percent,
                    'discount_read' => $latestNav->discountLabel(),
                    'nav_observations' => $navHistory->count(),
                ],
                'model' => $result['model'],
                'input_tokens' => $result['input_tokens'],
                'output_tokens' => $result['output_tokens'],
                'latency_ms' => $result['latency_ms'],
            ],
        );

        Log::channel('analysis')->info('Fund signal recorded', [
            'symbol' => $fund->symbol,
            'signal' => $signal->value,
            'nav' => $latestNav->nav,
            'premium_discount' => $latestNav->premium_discount_percent,
        ]);

        return $stockSignal;
    }

    private function clampInt(mixed $value, int $min, int $max): int
    {
        $int = is_numeric($value) ? (int) $value : $min;

        return max($min, min($max, $int));
    }

    private function clampFloat(mixed $value, float $min, float $max): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return round(max($min, min($max, (float) $value)), 2);
    }
}
