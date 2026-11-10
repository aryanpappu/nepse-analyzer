<?php

declare(strict_types=1);

namespace App\Services\AI\Prompts;

use App\Services\Analysis\ScripComparator;

/**
 * Prompt — "which of these scrips is the better holding right now".
 *
 * The table is computed before the model sees it, deliberately. Arithmetic and
 * winner-picking are deterministic and belong in {@see ScripComparator};
 * what the model adds is the part the tally cannot capture — that a two-metric
 * lead on payout ratios does not outweigh a doubling of non-performing loans.
 *
 * The system prompt therefore spends most of its length telling the model NOT
 * to re-derive the numbers, and to disagree with the tally when the weighting
 * warrants it.
 */
final class ScripComparisonPrompt
{
    public function system(): string
    {
        return <<<'PROMPT'
        You are an equity analyst covering the Nepal Stock Exchange (NEPSE),
        asked to compare listed scrips head to head for an informed retail
        investor.

        Market context you must apply:
        - NEPSE is a frontier, retail-dominated market. Liquidity is thin
          outside the large commercial banks.
        - Bonus issues, not buybacks, are how Nepali companies return value.
          Distributable EPS (retained earnings per share) is therefore the
          single best predictor of the next bonus, and DEPS yield is how the
          market prices that expectation.
        - Judge multiples against the sector, never against developed-market
          norms.

        How to reason:
        - The metric table and the per-metric winners have already been computed
          and are given to you. Do not recompute them, and do not contradict a
          stated number. If a figure looks wrong, say so explicitly rather than
          silently substituting your own.
        - The tally counts metrics; it does not weigh them. Your job is the
          weighing. A scrip that wins nine cosmetic metrics and loses on asset
          quality is not the better holding, and you should say so plainly.
        - For banks and microfinance, credit quality (non-performing loans) and
          capital adequacy dominate. A rising NPL outweighs a favourable P/E.
        - Distinguish "cheaper" from "better". A lower share price is not an
          advantage; a lower P/E or P/B against the same sector is.
        - Metrics marked as not reported by every scrip were excluded from the
          tally. Say when a missing metric is material to the comparison.
        - Different scrips may report different fiscal periods. If they do, note
          it — comparing a Q4 filing against a Q3 one flatters the Q4 side on
          every annualised figure.

        Be direct. Name a winner, or say plainly that the numbers do not
        separate them. This is analysis for an informed investor, not a
        regulated recommendation.
        PROMPT;
    }

    /**
     * @param  array<string, mixed>  $comparison  Output of ScripComparator::compare().
     */
    public function user(array $comparison): string
    {
        $symbols = $comparison['symbols'];
        $lines = [];

        $lines[] = 'Compare '.implode(' vs ', $symbols).'.';
        $lines[] = '';
        $lines[] = '## The scrips';

        foreach ($comparison['scrips'] as $scrip) {
            $lines[] = sprintf(
                '- %s (%s) — sector: %s, figures as filed for %s, %s%% of the '
                .'scored metric set reported.',
                $scrip['symbol'],
                $scrip['name'],
                $scrip['sector'] ?? 'unclassified',
                $scrip['fiscal_period'] ?? 'an unknown period',
                $scrip['data_coverage_percent'],
            );
        }

        $lines[] = '';
        $lines[] = '## Metric table';
        $lines[] = 'metric | '.implode(' | ', $symbols).' | better | winner';

        foreach ($comparison['metrics'] as $metric) {
            $cells = [];

            foreach ($symbols as $symbol) {
                $cells[] = $metric['values'][$symbol]['display'];
            }

            $lines[] = sprintf(
                '%s | %s | %s | %s',
                $metric['label'],
                implode(' | ', $cells),
                $metric['better'],
                $this->verdictCell($metric),
            );
        }

        $lines[] = '';
        $lines[] = '## Tally';
        $lines[] = sprintf(
            '%d metrics were contested (reported by every scrip). Wins: %s.',
            $comparison['contested_metrics'],
            $this->scoreboard($comparison['scoreboard']),
        );
        $lines[] = $comparison['verdict']['note'];

        $lines[] = '';
        $lines[] = '## What each metric means here';

        foreach ($comparison['metrics'] as $metric) {
            $lines[] = sprintf('- %s: %s', $metric['label'], $metric['rationale']);
        }

        $lines[] = '';
        $lines[] = 'Return your verdict using the record_comparison tool.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $metric
     */
    private function verdictCell(array $metric): string
    {
        if (! $metric['scored']) {
            return 'context only, not scored';
        }

        if (! $metric['contested']) {
            return 'not reported by all, excluded';
        }

        return $metric['tie'] ? 'tie' : (string) $metric['winner'];
    }

    /**
     * @param  array<string, int>  $scoreboard
     */
    private function scoreboard(array $scoreboard): string
    {
        $parts = [];

        foreach ($scoreboard as $symbol => $wins) {
            $parts[] = $symbol.' '.$wins;
        }

        return implode(', ', $parts);
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
                'winner' => [
                    'type' => 'string',
                    'description' => 'Symbol of the better holding right now, or "none" '
                        .'if the numbers genuinely do not separate them.',
                ],
                'confidence' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 100,
                    'description' => 'Use below 50 when the metric tally and the weighted '
                        .'judgement point different ways, or when coverage is thin.',
                ],
                'agrees_with_tally' => [
                    'type' => 'boolean',
                    'description' => 'False when your verdict differs from the counted winner. '
                        .'Explain why in disagreement_reason.',
                ],
                'disagreement_reason' => [
                    'type' => 'string',
                    'description' => 'Why the weighted view differs from the count. Empty when it does not.',
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'Two or three sentences an investor can act on.',
                ],
                'decisive_metrics' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'metric' => ['type' => 'string'],
                            'favours' => ['type' => 'string'],
                            'why_it_matters' => ['type' => 'string'],
                        ],
                        'required' => ['metric', 'favours', 'why_it_matters'],
                    ],
                    'description' => 'The few metrics that actually drive the verdict, most important first.',
                ],
                'per_scrip' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'symbol' => ['type' => 'string'],
                            'case_for' => ['type' => 'string'],
                            'case_against' => ['type' => 'string'],
                            'suits_investor' => [
                                'type' => 'string',
                                'description' => 'Which kind of holder this scrip suits — for example '
                                    .'income, bonus-expectation, or growth.',
                            ],
                        ],
                        'required' => ['symbol', 'case_for', 'case_against'],
                    ],
                ],
                'data_gaps' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Missing or mismatched figures that materially limit this comparison, '
                        .'including differing fiscal periods.',
                ],
            ],
            'required' => ['winner', 'confidence', 'agrees_with_tally', 'summary', 'decisive_metrics', 'per_scrip'],
        ];
    }
}
