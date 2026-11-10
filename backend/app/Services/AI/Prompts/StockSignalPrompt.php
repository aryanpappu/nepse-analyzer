<?php

declare(strict_types=1);

namespace App\Services\AI\Prompts;

use App\Enums\InvestorProfile;
use App\Models\Stock;
use App\Models\StockFundamental;
use App\Models\StockPrice;
use App\Models\TechnicalIndicator;
use App\Services\Analysis\MetricCatalog;
use Illuminate\Support\Collection;

/**
 * Prompt #1 — per-stock trading signal.
 *
 * The design goal is that the model reasons about *this* market: NEPSE is a
 * small, retail-dominated, circuit-broken exchange where liquidity matters more
 * than it does on a developed market. Those constraints are stated explicitly
 * rather than left for the model to infer.
 */
final class StockSignalPrompt
{
    public function system(): string
    {
        return <<<'PROMPT'
        You are an equity analyst covering the Nepal Stock Exchange (NEPSE).

        Market context you must apply:
        - NEPSE is a frontier, retail-dominated market. Liquidity is thin outside
          the large commercial banks, so a technically attractive setup in an
          illiquid scrip is not actionable at size.
        - A daily circuit breaker caps single-session moves at roughly +/-10%.
          Never propose an entry, target or stop that implies a larger one-day move.
        - Settlement is T+2 and there is no intraday short selling, so every
          recommendation must be a long-side view: buy, hold, or exit.
        - Prices are in Nepalese rupees (NPR). Lot sizes are 10 shares for most
          scrips; quantities should be sensible round numbers.

        How to reason:
        - Weigh the technical picture (trend, momentum, volume confirmation)
          together with valuation where fundamentals are supplied.
        - Volume matters: a breakout on below-average volume is weak evidence.
        - Judge valuation against the sector percentiles supplied, not against
          developed-market norms. A P/E of 19 is unremarkable for a Nepali
          commercial bank and expensive for a hydropower developer.
        - Distributable EPS (DEPS) is retained earnings per share: the pool a
          company may actually pay out, and the ceiling on the next dividend.
          It is not the period's profit. A high DEPS alongside a weak EPS means
          accumulated reserves rather than current earning power, and a high
          DEPS yield is the strongest single argument for a bonus-issue
          expectation — which is what moves NEPSE bank prices.
        - Filed regulatory ratios outrank derived ones. Where NPL, capital
          adequacy or base rate are supplied, use them: rising NPL invalidates a
          bull case on a bank no matter how the chart looks.
        - Be explicit about what would invalidate your view — that is the stop.
        - Confidence must reflect genuine evidence strength. If the indicators
          disagree with each other, or history is short, say so with a low
          confidence rather than manufacturing certainty.
        - Never invent data. If a metric was not supplied, reason without it and
          note the gap in your rationale.

        You are producing analysis for an informed retail investor, not a
        regulated recommendation. Be direct and specific about levels.

        You will be told which kind of investor you are writing for. Answer
        their question, not a generic one: the same scrip is a different
        proposition to someone holding for three years and someone holding for
        three days, and levels that suit one are useless to the other. Do not
        hedge by covering every horizon.
        PROMPT;
    }

    /**
     * The brief that tells the model whose question it is answering.
     *
     * Kept next to the system prompt rather than inside the data section: it is
     * instruction, not evidence, and it has to outrank the chart when the two
     * suggest different horizons.
     */
    private function profileBrief(InvestorProfile $profile): string
    {
        [$targetOne, $targetTwo] = $profile->targetBands();

        $lines = [
            '## Who you are writing for',
            sprintf('- Investor type: %s.', $profile->label()),
            sprintf('- Their approach: %s', $profile->description()),
            sprintf('- Intended holding period: %s. Your time_horizon must match this.',
                $profile->holdLabel()),
            sprintf('- Their targets are typically %+.0f%% and %+.0f%% from entry; a stop wider '
                .'than %.0f%% is outside what they will accept.',
                $targetOne * 100, $targetTwo * 100, $profile->maxStopLoss() * 100),
        ];

        $lines[] = match ($profile) {
            InvestorProfile::LongTerm => '- Weight earnings quality, book value, dividend capacity '
                .'and the multi-year trend. A weak RSI or a soft month is not a reason to avoid a '
                .'sound business at a fair price. Do not recommend an exit over short-term momentum.',
            InvestorProfile::Swing => '- Weight the technical setup: where price sits against its '
                .'20 and 50 day averages, the Bollinger band, RSI turning from oversold, MACD '
                .'crossing. Fundamentals matter only as a filter against a broken company.',
            InvestorProfile::Momentum => '- Weight thrust and confirmation: a breakout through '
                .'recent highs on above-average volume. Cheapness is irrelevant. If the move is '
                .'not being paid for in volume, say so and stay out. Exit fast when thrust fails.',
        };

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<int, StockPrice>  $history  Oldest first.
     * @param  array<string, array{percentile: float, sample: int, better: string}>  $sectorPercentiles
     *                                                                                                   Where the scrip ranks against its own
     *                                                                                                   sector, from ScripComparator. Empty
     *                                                                                                   when the sector is too small to rank.
     */
    public function user(
        Stock $stock,
        Collection $history,
        ?TechnicalIndicator $indicator,
        ?StockFundamental $fundamental,
        array $sectorPercentiles = [],
        ?InvestorProfile $profile = null,
    ): string {
        $latest = $history->last();
        $sector = $stock->sector?->name ?? 'Unclassified';

        $lines = [];
        $lines[] = "Analyse {$stock->symbol} ({$stock->name}) — sector: {$sector}.";
        $lines[] = '';

        // The brief goes first: it frames every number that follows.
        if ($profile !== null) {
            $lines[] = $this->profileBrief($profile);
            $lines[] = '';
        }

        $lines[] = '## Current quote';
        $lines[] = sprintf('- Last close: NPR %s', number_format((float) $latest->close, 2));

        if ($latest->previous_close !== null) {
            $lines[] = sprintf('- Previous close: NPR %s', number_format((float) $latest->previous_close, 2));
        }

        if ($latest->change_percent !== null) {
            $lines[] = sprintf('- Session change: %+.2f%%', (float) $latest->change_percent);
        }

        $lines[] = sprintf('- Volume: %s shares', number_format((float) $latest->volume));
        $lines[] = sprintf('- Turnover: NPR %s', number_format((float) $latest->turnover, 2));

        if ($latest->fifty_two_week_high !== null && $latest->fifty_two_week_low !== null) {
            $lines[] = sprintf(
                '- 52-week range: NPR %s - %s',
                number_format((float) $latest->fifty_two_week_low, 2),
                number_format((float) $latest->fifty_two_week_high, 2),
            );
        }

        $lines[] = '';
        $lines[] = $this->historyBlock($history);

        if ($indicator !== null) {
            $lines[] = '';
            $lines[] = $this->indicatorBlock($indicator, (float) $latest->close);
        }

        if ($fundamental !== null) {
            $lines[] = '';
            $lines[] = $this->fundamentalBlock($fundamental, $stock);
        }

        if ($sectorPercentiles !== []) {
            $lines[] = '';
            $lines[] = $this->sectorBlock($stock, $sectorPercentiles);
        }

        $lines[] = '';
        $lines[] = 'Return your analysis using the record_stock_signal tool. '
            .'All prices must be in NPR and consistent with the levels above.';

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<int, StockPrice>  $history
     */
    private function historyBlock(Collection $history): string
    {
        $days = (int) config('nepse.analysis.prompt_history_days', 60);
        $window = $history->slice(-$days);

        $rows = ['## Recent price history (oldest first)', 'date | open | high | low | close | volume'];

        foreach ($window as $price) {
            $rows[] = sprintf(
                '%s | %s | %s | %s | %s | %s',
                $price->trade_date->toDateString(),
                $this->fmt($price->open),
                $this->fmt($price->high),
                $this->fmt($price->low),
                $this->fmt($price->close),
                number_format((float) $price->volume),
            );
        }

        $first = $window->first();
        $last = $window->last();

        if ($first !== null && $last !== null && (float) $first->close > 0) {
            $change = ((float) $last->close - (float) $first->close) / (float) $first->close * 100;
            $rows[] = '';
            $rows[] = sprintf(
                'Over these %d sessions the close moved %+.2f%%.',
                $window->count(),
                $change,
            );
        }

        return implode("\n", $rows);
    }

    private function indicatorBlock(TechnicalIndicator $indicator, float $close): string
    {
        $rows = ['## Technical indicators (latest session)'];

        if ($indicator->rsi_14 !== null) {
            $rows[] = sprintf('- RSI(14): %.2f (%s)', $indicator->rsi_14, $indicator->rsiZone() ?? 'n/a');
        }

        if ($indicator->macd !== null) {
            $rows[] = sprintf(
                '- MACD: %.4f, signal %.4f, histogram %.4f (%s)',
                $indicator->macd,
                $indicator->macd_signal ?? 0.0,
                $indicator->macd_histogram ?? 0.0,
                ($indicator->macd_histogram ?? 0) >= 0 ? 'bullish crossover territory' : 'bearish crossover territory',
            );
        }

        foreach (['sma_20' => 'SMA 20', 'sma_50' => 'SMA 50', 'sma_200' => 'SMA 200'] as $field => $label) {
            if ($indicator->{$field} !== null) {
                $delta = $close - $indicator->{$field};
                $rows[] = sprintf(
                    '- %s: %.2f (price is %.2f%% %s)',
                    $label,
                    $indicator->{$field},
                    abs($delta) / max($indicator->{$field}, 0.0001) * 100,
                    $delta >= 0 ? 'above' : 'below',
                );
            }
        }

        if ($indicator->bollinger_upper !== null && $indicator->bollinger_lower !== null) {
            $rows[] = sprintf(
                '- Bollinger(20,2): upper %.2f, middle %.2f, lower %.2f',
                $indicator->bollinger_upper,
                $indicator->bollinger_middle ?? 0.0,
                $indicator->bollinger_lower,
            );
        }

        if ($indicator->atr_14 !== null) {
            $rows[] = sprintf(
                '- ATR(14): %.2f (%.2f%% of price — use this to size the stop)',
                $indicator->atr_14,
                $indicator->atr_14 / max($close, 0.0001) * 100,
            );
        }

        if ($indicator->volume_sma_20 !== null) {
            $rows[] = sprintf('- 20-day average volume: %s shares', number_format($indicator->volume_sma_20));
        }

        if ($indicator->trend !== null) {
            $rows[] = sprintf(
                '- Composite trend read: %s (strength %.0f/100)',
                $indicator->trend,
                $indicator->trend_strength ?? 0.0,
            );
        }

        return implode("\n", $rows);
    }

    private function fundamentalBlock(StockFundamental $fundamental, Stock $stock): string
    {
        $rows = ['## Fundamentals (as filed, '.$fundamental->fiscal_period.')'];

        $earnings = [
            'EPS (annualised)' => $fundamental->eps,
            'Distributable EPS' => $fundamental->distributable_eps,
            'DEPS yield %' => $fundamental->distributable_eps_yield,
            'Book value per share' => $fundamental->book_value,
            'P/E' => $fundamental->pe_ratio,
            'P/B' => $fundamental->pb_ratio,
            'ROE %' => $fundamental->roe,
            'ROA %' => $fundamental->roa,
        ];

        $payout = [
            'Total dividend %' => $fundamental->dividend_percent,
            '  of which bonus %' => $fundamental->bonus_percent,
            '  of which cash %' => $fundamental->cash_dividend_percent,
            'Cash dividend yield %' => $fundamental->dividend_yield,
        ];

        $balanceSheet = [
            'Share capital' => $fundamental->share_capital,
            'Retained earnings' => $fundamental->retained_earnings,
            'Total equity' => $fundamental->total_equity,
            'Total assets' => $fundamental->total_assets,
            'Net profit' => $fundamental->net_profit,
            'Market capitalisation' => $stock->market_capitalization,
        ];

        // Sector-specific prudential ratios. Absent for anything that is not a
        // bank or microfinance, and that absence is normal rather than a gap.
        $prudential = [
            'Non-performing loans %' => $fundamental->npl_percent,
            'Capital adequacy %' => $fundamental->capital_adequacy_ratio,
            'Base rate %' => $fundamental->base_rate,
        ];

        foreach ($earnings as $label => $value) {
            if ($value !== null) {
                $rows[] = sprintf('- %s: %s', $label, number_format((float) $value, 2));
            }
        }

        foreach ($payout as $label => $value) {
            if ($value !== null) {
                $rows[] = sprintf('- %s: %s', $label, number_format((float) $value, 2));
            }
        }

        foreach ($balanceSheet as $label => $value) {
            if ($value !== null) {
                $rows[] = sprintf('- %s: NPR %s', $label, $this->money((float) $value));
            }
        }

        $prudentialRows = [];

        foreach ($prudential as $label => $value) {
            if ($value !== null) {
                $prudentialRows[] = sprintf('- %s: %s', $label, number_format((float) $value, 2));
            }
        }

        if ($prudentialRows !== []) {
            $rows[] = '';
            $rows[] = 'Regulatory ratios as filed:';
            $rows = array_merge($rows, $prudentialRows);
        }

        if (count($rows) === 1) {
            return "## Fundamentals\n- Not available for this scrip.";
        }

        if ($fundamental->distributable_eps !== null) {
            $rows[] = '';
            $rows[] = 'Distributable EPS is retained earnings per share — the pool available '
                .'for the next dividend, not the period\'s profit. Read it alongside EPS, '
                .'not instead of it: a high DEPS with a weak EPS is an accumulated '
                .'balance, not current earning power.';
        }

        // Naming the gaps matters more than it looks: without this the model
        // reads an absent P/E as "not a concern" rather than "not known", and
        // states valuation conclusions it has no basis for.
        $missing = array_keys(array_filter([
            'EPS' => $fundamental->eps === null,
            'P/E' => $fundamental->pe_ratio === null,
            'book value' => $fundamental->book_value === null,
            'ROE' => $fundamental->roe === null,
            'distributable EPS' => $fundamental->distributable_eps === null,
        ]));

        if ($missing !== []) {
            $rows[] = '';
            $rows[] = 'Not published for this scrip: '.implode(', ', $missing)
                .'. Do not infer or estimate them — reason only from what is listed above.';
        }

        return implode("\n", $rows);
    }

    /**
     * Where this scrip sits against its own sector.
     *
     * Valuation is relative: a P/E of 19 is expensive for a commercial bank and
     * cheap for a hydropower developer. Without this the model anchors on
     * developed-market norms it learned in training and calls half of NEPSE
     * overvalued.
     *
     * @param  array<string, array{percentile: float, sample: int, better: string}>  $percentiles
     */
    private function sectorBlock(Stock $stock, array $percentiles): string
    {
        $catalog = MetricCatalog::keyed();
        $sector = $stock->sector?->name ?? 'its sector';

        $rows = [];
        $thin = false;

        foreach ($percentiles as $key => $entry) {
            if (! isset($catalog[$key])) {
                continue;
            }

            // Each metric has its own population — a sector where nineteen
            // scrips publish a P/E and three publish an NPL is the normal case.
            // Quoting one headline peer count would present a percentile drawn
            // from three companies as if nineteen backed it.
            $thin = $thin || $entry['sample'] < 5;

            $rows[] = sprintf(
                '- %s: better than %.0f%% of %d reporting peers (%s is better)',
                $catalog[$key]->label,
                $entry['percentile'],
                $entry['sample'],
                $entry['better'],
            );
        }

        if ($rows === []) {
            return '';
        }

        array_unshift($rows, sprintf('## Standing within %s', $sector));

        $rows[] = '';
        $rows[] = 'These percentiles are against NEPSE peers in the same sector, which is the '
            .'only fair benchmark here — do not judge these multiples against '
            .'developed-market norms.';

        if ($thin) {
            $rows[] = 'Some of these populations are small. Treat a percentile drawn from '
                .'under five reporting peers as weak evidence, and say so if you lean on it.';
        }

        return implode("\n", $rows);
    }

    /**
     * NEPSE balance sheets run to twelve digits; printing them in full wastes
     * prompt budget and is harder to read than the abbreviated form.
     */
    private function money(float $value): string
    {
        $abs = abs($value);

        if ($abs >= 1_000_000_000) {
            return number_format($value / 1_000_000_000, 2).'B';
        }

        if ($abs >= 1_000_000) {
            return number_format($value / 1_000_000, 0).'M';
        }

        return number_format($value, 2);
    }

    private function fmt(mixed $value): string
    {
        return $value === null ? '-' : number_format((float) $value, 2);
    }

    /**
     * JSON Schema for the forced tool call.
     *
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'signal' => [
                    'type' => 'string',
                    'enum' => ['buy', 'sell', 'hold'],
                    'description' => 'The action an investor should take on this scrip now.',
                ],
                'confidence' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 100,
                    'description' => 'How strong the supporting evidence is. Use below 50 when indicators conflict or history is short.',
                ],
                'risk_level' => [
                    'type' => 'string',
                    'enum' => ['low', 'medium', 'high'],
                ],
                'time_horizon' => [
                    'type' => 'string',
                    'enum' => ['short_term', 'medium_term', 'long_term'],
                    'description' => 'short_term = days to weeks, medium_term = 1-6 months, long_term = 6+ months.',
                ],
                'entry_price' => [
                    'type' => 'number',
                    'description' => 'Lower bound of the buy zone in NPR. For a sell signal, the price at which to begin exiting.',
                ],
                'entry_price_max' => [
                    'type' => 'number',
                    'description' => 'Upper bound of the buy zone in NPR. Must be >= entry_price.',
                ],
                'exit_price' => [
                    'type' => 'number',
                    'description' => 'Price at which the thesis is fully realised and the position should be closed.',
                ],
                'stop_loss' => [
                    'type' => 'number',
                    'description' => 'Level that invalidates the thesis. Must be below entry_price for a buy.',
                ],
                'target_1' => [
                    'type' => 'number',
                    'description' => 'First profit target in NPR.',
                ],
                'target_2' => [
                    'type' => 'number',
                    'description' => 'Second, more ambitious profit target in NPR. Must be beyond target_1.',
                ],
                'expected_return_percent' => [
                    'type' => 'number',
                    'description' => 'Expected percentage return if the thesis plays out to target_1.',
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'Two or three sentences an investor can act on. No hedging boilerplate.',
                ],
                'rationale' => [
                    'type' => 'object',
                    'properties' => [
                        'bull_case' => ['type' => 'string'],
                        'bear_case' => ['type' => 'string'],
                        'key_drivers' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'The specific observations driving the call.',
                        ],
                        'invalidation' => [
                            'type' => 'string',
                            'description' => 'What would prove this view wrong.',
                        ],
                        'data_gaps' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Metrics that were unavailable and would have changed the analysis.',
                        ],
                    ],
                    'required' => ['bull_case', 'bear_case', 'key_drivers', 'invalidation'],
                ],
            ],
            'required' => [
                'signal', 'confidence', 'risk_level', 'time_horizon',
                'stop_loss', 'target_1', 'summary', 'rationale',
            ],
        ];
    }
}
