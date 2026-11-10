<?php

declare(strict_types=1);

namespace App\Services\AI\Prompts;

use App\Enums\InvestorProfile;
use App\Models\Portfolio;

/**
 * Prompt #4 — what to buy with the cash currently sitting idle.
 *
 * This is the one prompt that must respect a hard budget: the model is told the
 * exact investable amount and is required to keep total estimated cost within
 * it. The service layer re-checks the arithmetic afterwards regardless.
 */
final class BuyRecommendationPrompt
{
    public function system(): string
    {
        return <<<'PROMPT'
        You are advising a NEPSE investor on how to deploy idle cash.

        Hard constraints:
        - You are given an exact investable cash figure. The total estimated cost
          of everything you recommend must not exceed it. Leaving part of it
          uninvested is a legitimate and often correct answer — say so via the
          reserve amount when valuations do not justify full deployment.
        - Quantities must be whole numbers of shares, and for most NEPSE scrips
          the trading lot is 10 shares. Prefer multiples of 10.
        - Estimated cost per line = suggested quantity x suggested price, plus
          roughly 0.4% broker commission. Account for that in your totals.
        - Use the supplied last-traded prices. Do not invent a price.

        How to choose:
        - Start from what the portfolio already owns. The best use of new cash is
          usually to fix the portfolio's biggest weakness, not to buy whatever
          scores highest in isolation. Adding to an already-dominant sector is
          rarely the right answer.
        - Prefer candidates whose existing signal and fundamentals agree.
        - Respect liquidity: do not recommend a position size that is large
          relative to the scrip's recent daily turnover.
        - Diversify across distinct economic drivers, not across four kinds of
          bank. See the sector allocation supplied.
        - It is better to recommend three convictions well-sized than ten token
          positions that cannot move the portfolio.

        Every recommendation needs an entry price, a stop loss and a target, so
        the investor knows the exit before entering.
        PROMPT;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<array<string, mixed>>  $holdings
     * @param  list<array<string, mixed>>  $candidates
     */
    public function user(Portfolio $portfolio, array $summary, array $holdings, array $candidates, float $investableCash, InvestorProfile $profile = InvestorProfile::LongTerm): string
    {
        $lines = [];
        $lines[] = 'Investor profile: '.$profile->label().' ('.$profile->value.').';
        $lines[] = $profile->description();
        $lines[] = sprintf('Holding window: %s. Maximum stop loss: %.1f%%. Position weight guidance: %.1f%%.', $profile->holdLabel(), $profile->maxStopLoss() * 100, $profile->maxPositionWeight() * 100);
        $lines[] = 'Use only candidates from this profile; match its horizon and risk constraints.';
        $lines[] = sprintf(
            'Recommend how to deploy NPR %s of idle cash in the portfolio "%s".',
            number_format($investableCash, 2),
            $portfolio->name,
        );
        $lines[] = '';
        $lines[] = '## Budget';
        $lines[] = sprintf('- Cash in hand: NPR %s', number_format((float) $summary['cash_in_hand'], 2));
        $lines[] = sprintf('- Bank balance: NPR %s', number_format((float) $summary['bank_balance'], 2));
        $lines[] = sprintf('- TOTAL INVESTABLE: NPR %s — do not exceed this.', number_format($investableCash, 2));
        $lines[] = sprintf('- Current equity value: NPR %s', number_format((float) $summary['total_value'], 2));

        $lines[] = '';
        $lines[] = '## Current holdings';

        if ($holdings === []) {
            $lines[] = '- The portfolio is empty; this is a first allocation.';
        } else {
            $lines[] = 'symbol | sector | qty | avg cost | last price | value | weight % | P/L %';

            foreach ($holdings as $holding) {
                $lines[] = sprintf(
                    '%s | %s | %s | %s | %s | %s | %s | %s',
                    $holding['symbol'],
                    $holding['sector'] ?? 'Unclassified',
                    number_format((float) $holding['quantity']),
                    number_format((float) $holding['average_cost'], 2),
                    $holding['current_price'] !== null ? number_format((float) $holding['current_price'], 2) : 'n/a',
                    $holding['current_value'] !== null ? number_format((float) $holding['current_value'], 2) : 'n/a',
                    $holding['weight_percent'] !== null ? sprintf('%.2f', (float) $holding['weight_percent']) : 'n/a',
                    $holding['unrealized_gain_percent'] !== null
                        ? sprintf('%+.2f', (float) $holding['unrealized_gain_percent'])
                        : 'n/a',
                );
            }
        }

        $lines[] = '';
        $lines[] = '## Current sector allocation';

        if (empty($summary['sector_allocation'])) {
            $lines[] = '- None yet.';
        } else {
            foreach ((array) $summary['sector_allocation'] as $sector) {
                $lines[] = sprintf('- %s: %.2f%%', $sector['sector'], (float) $sector['percent']);
            }
        }

        $lines[] = '';
        $lines[] = '## Candidate scrips';
        $lines[] = 'These carry a current signal for the selected investor profile. Recommend only symbols in this candidate table, including additions to existing holdings.';
        $lines[] = '';
        $lines[] = 'symbol | name | sector | last price | signal | confidence | risk | RSI | trend | P/E | 20d avg turnover';

        foreach ($candidates as $candidate) {
            $lines[] = sprintf(
                '%s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s',
                $candidate['symbol'],
                $candidate['name'],
                $candidate['sector'] ?? 'Unclassified',
                $candidate['last_price'] !== null ? number_format((float) $candidate['last_price'], 2) : 'n/a',
                $candidate['signal'] ?? 'none',
                $candidate['confidence'] ?? 'n/a',
                $candidate['risk_level'] ?? 'n/a',
                $candidate['rsi'] !== null ? sprintf('%.1f', (float) $candidate['rsi']) : 'n/a',
                $candidate['trend'] ?? 'n/a',
                $candidate['pe_ratio'] !== null ? sprintf('%.2f', (float) $candidate['pe_ratio']) : 'n/a',
                $candidate['avg_turnover'] !== null ? number_format((float) $candidate['avg_turnover']) : 'n/a',
            );
        }

        $lines[] = '';
        $lines[] = 'Return your plan with the record_buy_recommendations tool. The sum of '
            .'estimated_cost across all recommendations plus reserve_amount must equal the '
            .'total investable cash.';

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
                'strategy_summary' => [
                    'type' => 'string',
                    'description' => 'The reasoning behind this allocation as a whole, in a few sentences.',
                ],
                'reserve_amount' => [
                    'type' => 'number',
                    'description' => 'NPR deliberately left uninvested. Use a meaningful number when valuations do not justify full deployment.',
                ],
                'recommendations' => [
                    'type' => 'array',
                    'description' => 'Ordered best-first. May be empty if nothing is worth buying now.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'symbol' => ['type' => 'string', 'description' => 'NEPSE ticker, exactly as listed in the candidates.'],
                            'action' => ['type' => 'string', 'enum' => ['buy', 'add']],
                            'suggested_quantity' => [
                                'type' => 'integer',
                                'description' => 'Whole shares, preferably a multiple of 10.',
                            ],
                            'suggested_price' => [
                                'type' => 'number',
                                'description' => 'Limit price in NPR to place the order at.',
                            ],
                            'estimated_cost' => [
                                'type' => 'number',
                                'description' => 'quantity x price plus ~0.4% commission, in NPR.',
                            ],
                            'allocation_percent' => [
                                'type' => 'number',
                                'description' => 'Share of the investable cash this line consumes.',
                            ],
                            'stop_loss' => ['type' => 'number'],
                            'target_price' => ['type' => 'number'],
                            'conviction' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                            'risk_level' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                            'time_horizon' => ['type' => 'string', 'enum' => ['short_term', 'medium_term', 'long_term']],
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Why this scrip, at this size, for this portfolio specifically.',
                            ],
                        ],
                        'required' => [
                            'symbol', 'action', 'suggested_quantity', 'suggested_price',
                            'estimated_cost', 'conviction', 'reason',
                        ],
                    ],
                ],
                'avoid' => [
                    'type' => 'array',
                    'description' => 'Candidates deliberately passed over, with the reason.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'symbol' => ['type' => 'string'],
                            'reason' => ['type' => 'string'],
                        ],
                        'required' => ['symbol', 'reason'],
                    ],
                ],
            ],
            'required' => ['strategy_summary', 'recommendations', 'reserve_amount'],
        ];
    }
}
