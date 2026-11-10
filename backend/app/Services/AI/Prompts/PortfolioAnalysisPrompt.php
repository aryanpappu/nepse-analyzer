<?php

declare(strict_types=1);

namespace App\Services\AI\Prompts;

use App\Models\Portfolio;

/**
 * Prompt #2 — portfolio health, sector allocation and rebalancing.
 */
final class PortfolioAnalysisPrompt
{
    public function system(): string
    {
        return <<<'PROMPT'
        You are a portfolio adviser for investors in the Nepal Stock Exchange (NEPSE).

        Market realities you must apply:
        - NEPSE is heavily weighted toward commercial banks, development banks,
          finance companies, microfinance and insurance. A portfolio that looks
          "diversified" across four of those is still one concentrated bet on
          Nepali financial-sector credit conditions. Say so when it is true.
        - Genuine diversification here means spanning distinct economic drivers:
          banking, hydropower, manufacturing, hotels/tourism, trading, and
          non-financial services.
        - Liquidity is uneven. Recommending an exit from a thinly traded scrip
          has real execution cost; acknowledge it rather than assuming a fill.
        - Selling incurs brokerage plus capital gains tax, so churn is expensive.
          Only recommend a trade when the reasoning outweighs those frictions.

        How to score health (0-100):
        - 80-100: well spread across unrelated drivers, no position dominating,
          positions backed by sound fundamentals.
        - 60-79: workable but with a clear identifiable weakness.
        - 40-59: meaningful concentration or quality problems.
        - Below 40: dangerously concentrated or built on weak businesses.

        Be specific and quantitative. "Reduce banking exposure" is not useful;
        "trim NABIL by roughly 100 shares to bring banking from 62% to 45%" is.
        Never invent holdings, prices or metrics that were not provided.
        PROMPT;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<array<string, mixed>>  $holdings
     */
    public function user(Portfolio $portfolio, array $summary, array $holdings): string
    {
        $lines = [];
        $lines[] = "Analyse the portfolio \"{$portfolio->name}\" (currency: {$portfolio->currency}).";
        $lines[] = '';
        $lines[] = '## Totals';
        $lines[] = sprintf('- Invested cost base: NPR %s', number_format((float) $summary['total_invested'], 2));
        $lines[] = sprintf('- Current market value: NPR %s', number_format((float) $summary['total_value'], 2));
        $lines[] = sprintf(
            '- Unrealised P/L: NPR %s (%+.2f%%)',
            number_format((float) $summary['unrealized_gain'], 2),
            (float) $summary['unrealized_gain_percent'],
        );
        $lines[] = sprintf('- Realised P/L to date: NPR %s', number_format((float) $summary['realized_gain'], 2));
        $lines[] = sprintf('- Cash in hand: NPR %s', number_format((float) $summary['cash_in_hand'], 2));
        $lines[] = sprintf('- Bank balance: NPR %s', number_format((float) $summary['bank_balance'], 2));
        $lines[] = sprintf(
            '- Total investable cash: NPR %s (%.1f%% of net worth)',
            number_format((float) $summary['investable_cash'], 2),
            (float) $summary['net_worth'] > 0
                ? (float) $summary['investable_cash'] / (float) $summary['net_worth'] * 100
                : 0.0,
        );
        $lines[] = sprintf('- Number of open positions: %d', (int) $summary['holdings_count']);

        if (! empty($summary['unpriced_symbols'])) {
            $lines[] = sprintf(
                '- NOTE: no current price available for %s, so these are excluded from the valuation.',
                implode(', ', $summary['unpriced_symbols']),
            );
        }

        $lines[] = '';
        $lines[] = '## Holdings';
        $lines[] = 'symbol | name | sector | qty | avg cost | last price | invested | value | P/L % | weight % | latest signal';

        foreach ($holdings as $holding) {
            $lines[] = sprintf(
                '%s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s',
                $holding['symbol'],
                $holding['name'],
                $holding['sector'] ?? 'Unclassified',
                number_format((float) $holding['quantity']),
                number_format((float) $holding['average_cost'], 2),
                $holding['current_price'] !== null ? number_format((float) $holding['current_price'], 2) : 'n/a',
                number_format((float) $holding['invested'], 2),
                $holding['current_value'] !== null ? number_format((float) $holding['current_value'], 2) : 'n/a',
                $holding['unrealized_gain_percent'] !== null
                    ? sprintf('%+.2f', (float) $holding['unrealized_gain_percent'])
                    : 'n/a',
                $holding['weight_percent'] !== null ? sprintf('%.2f', (float) $holding['weight_percent']) : 'n/a',
                $holding['signal'] ?? 'none',
            );
        }

        $lines[] = '';
        $lines[] = '## Sector allocation';

        foreach ((array) $summary['sector_allocation'] as $sector) {
            $lines[] = sprintf(
                '- %s: %.2f%% (NPR %s) — %s',
                $sector['sector'],
                (float) $sector['percent'],
                number_format((float) $sector['value'], 2),
                implode(', ', (array) $sector['symbols']),
            );
        }

        $lines[] = '';
        $lines[] = 'Assess concentration, sector balance and overall quality, then return your '
            .'analysis with the record_portfolio_analysis tool. Rebalancing actions must be '
            .'expressed in share quantities and rupee amounts consistent with the holdings above.';

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'health_score' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 100,
                    'description' => 'Overall portfolio health, scored per the rubric in the system prompt.',
                ],
                'diversification_grade' => [
                    'type' => 'string',
                    'enum' => ['A', 'B', 'C', 'D', 'F'],
                ],
                'risk_level' => [
                    'type' => 'string',
                    'enum' => ['low', 'medium', 'high'],
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'A short, direct assessment the investor can act on.',
                ],
                'strengths' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'weaknesses' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'concentration_risks' => [
                    'type' => 'array',
                    'description' => 'Positions or sectors whose weight is a risk in itself.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'label' => ['type' => 'string', 'description' => 'Symbol or sector name.'],
                            'weight_percent' => ['type' => 'number'],
                            'severity' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                            'explanation' => ['type' => 'string'],
                        ],
                        'required' => ['label', 'severity', 'explanation'],
                    ],
                ],
                'rebalancing_actions' => [
                    'type' => 'array',
                    'description' => 'Concrete, ordered steps. Empty if the portfolio needs no changes.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => ['type' => 'string', 'enum' => ['buy', 'sell', 'trim', 'add', 'hold']],
                            'symbol' => ['type' => 'string'],
                            'quantity' => ['type' => 'number', 'description' => 'Number of shares.'],
                            'estimated_amount' => ['type' => 'number', 'description' => 'Approximate NPR value.'],
                            'priority' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                            'reason' => ['type' => 'string'],
                        ],
                        'required' => ['action', 'symbol', 'reason', 'priority'],
                    ],
                ],
                'target_sector_allocation' => [
                    'type' => 'array',
                    'description' => 'Suggested target weights by sector.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'sector' => ['type' => 'string'],
                            'current_percent' => ['type' => 'number'],
                            'target_percent' => ['type' => 'number'],
                        ],
                        'required' => ['sector', 'target_percent'],
                    ],
                ],
            ],
            'required' => ['health_score', 'diversification_grade', 'risk_level', 'summary', 'strengths', 'weaknesses'],
        ];
    }
}
