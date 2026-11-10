<?php

declare(strict_types=1);

namespace App\Services\AI\Prompts;

use App\Models\Stock;
use App\Models\StockFundamental;

/**
 * Prompt #3 — valuation, dividend sustainability and business quality.
 */
final class FundamentalAnalysisPrompt
{
    public function system(): string
    {
        return <<<'PROMPT'
        You are a fundamental equity analyst covering the Nepal Stock Exchange (NEPSE).

        Market conventions you must apply:
        - Nepali companies report on a Bikram Sambat fiscal year (mid-July to
          mid-July). A "Q4" figure is a full-year number.
        - Dividends are quoted as a percentage of the NPR 100 par value, not of
          the market price. A "20% dividend" is NPR 20 per share — so on a share
          trading at NPR 500 the actual yield is 4%. Never conflate the two.
        - Dividends are usually split into a cash component and a bonus-share
          component. Bonus shares are not income: they dilute EPS and are only
          value-accretive if earnings grow into the enlarged capital base.
        - Banks and insurers must meet NRB/Beema Samiti capital requirements, so
          a high payout ratio at a bank with thin capital adequacy is a real
          sustainability risk, not just a preference.
        - Typical NEPSE P/E ranges run higher than developed markets; judge
          valuation against the sector, not against a global benchmark.

        How to reason:
        - State plainly whether the metrics supplied are sufficient. Much of the
          data here is crawled from public pages and can be incomplete; if the
          key inputs for a judgement are missing, score conservatively and list
          the gap rather than guessing.
        - A fair value range is more honest than a point estimate.
        - Distinguish a cheap price from a cheap business.
        PROMPT;
    }

    /**
     * @param  array<string, mixed>  $sectorContext
     */
    public function user(Stock $stock, ?StockFundamental $fundamental, array $sectorContext = []): string
    {
        $lines = [];
        $sector = $stock->sector?->name ?? 'Unclassified';

        $lines[] = "Perform a fundamental analysis of {$stock->symbol} ({$stock->name}), sector: {$sector}.";
        $lines[] = '';
        $lines[] = '## Market data';

        if ($stock->last_price !== null) {
            $lines[] = sprintf('- Last price: NPR %s', number_format((float) $stock->last_price, 2));
        }

        if ($stock->listed_shares !== null) {
            $lines[] = sprintf('- Listed shares: %s', number_format((float) $stock->listed_shares));
        }

        if ($stock->market_capitalization !== null) {
            $lines[] = sprintf('- Market capitalisation: NPR %s', number_format((float) $stock->market_capitalization, 2));
        }

        $lines[] = '';

        if ($fundamental === null) {
            $lines[] = '## Reported fundamentals';
            $lines[] = 'No fundamental data could be crawled for this scrip. Score conservatively, '
                .'set valuation to what the price data alone supports, and list the missing inputs.';
        } else {
            $lines[] = '## Reported fundamentals ('.$fundamental->fiscal_period.')';

            $metrics = [
                'EPS (NPR)' => $fundamental->eps,
                'P/E ratio' => $fundamental->pe_ratio,
                'Book value per share (NPR)' => $fundamental->book_value,
                'Price/Book' => $fundamental->pb_ratio,
                'Total dividend %' => $fundamental->dividend_percent,
                'Cash dividend %' => $fundamental->cash_dividend_percent,
                'Bonus share %' => $fundamental->bonus_percent,
                'Dividend yield %' => $fundamental->dividend_yield,
                'ROE %' => $fundamental->roe,
                'ROA %' => $fundamental->roa,
                'Net profit (NPR)' => $fundamental->net_profit,
                'Net worth (NPR)' => $fundamental->net_worth,
                'Paid-up capital (NPR)' => $fundamental->paid_up_capital,
                'Reserve (NPR)' => $fundamental->reserve,
                'Non-performing loans %' => $fundamental->npl_percent,
                'Capital adequacy ratio %' => $fundamental->capital_adequacy_ratio,
                'Base rate %' => $fundamental->base_rate,
            ];

            $present = 0;

            foreach ($metrics as $label => $value) {
                if ($value !== null) {
                    $lines[] = sprintf('- %s: %s', $label, number_format((float) $value, 2));
                    $present++;
                }
            }

            if ($present === 0) {
                $lines[] = '- None of the standard metrics were available.';
            }
        }

        if ($sectorContext !== []) {
            $lines[] = '';
            $lines[] = '## Sector comparison ('.$sector.')';

            if (isset($sectorContext['median_pe'])) {
                $lines[] = sprintf('- Median P/E across the sector: %.2f', (float) $sectorContext['median_pe']);
            }

            if (isset($sectorContext['median_pb'])) {
                $lines[] = sprintf('- Median P/B across the sector: %.2f', (float) $sectorContext['median_pb']);
            }

            if (isset($sectorContext['median_roe'])) {
                $lines[] = sprintf('- Median ROE across the sector: %.2f%%', (float) $sectorContext['median_roe']);
            }

            if (isset($sectorContext['peer_count'])) {
                $lines[] = sprintf('- Peers with usable data: %d', (int) $sectorContext['peer_count']);
            }
        }

        $lines[] = '';
        $lines[] = 'Return your assessment with the record_fundamental_analysis tool.';

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
                'valuation' => [
                    'type' => 'string',
                    'enum' => ['undervalued', 'fairly_valued', 'overvalued'],
                    'description' => 'Judged against sector peers, not a global benchmark.',
                ],
                'quality_score' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 100,
                    'description' => 'Business quality: earnings consistency, returns on capital, franchise strength.',
                ],
                'financial_health_score' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 100,
                    'description' => 'Balance sheet strength: capital adequacy, asset quality, leverage.',
                ],
                'dividend_sustainability_score' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 100,
                    'description' => 'Whether the current payout can be maintained from earnings without weakening capital.',
                ],
                'fair_value_low' => [
                    'type' => 'number',
                    'description' => 'Lower bound of the estimated fair value per share in NPR.',
                ],
                'fair_value_high' => [
                    'type' => 'number',
                    'description' => 'Upper bound of the estimated fair value per share in NPR.',
                ],
                'sector_pe_comparison' => [
                    'type' => 'number',
                    'description' => "This company's P/E divided by the sector median. Above 1 means richer than peers.",
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'A direct verdict in a few sentences.',
                ],
                'strengths' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'concerns' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'dividend_assessment' => [
                    'type' => 'object',
                    'properties' => [
                        'cash_yield_percent' => [
                            'type' => 'number',
                            'description' => 'Cash dividend per share divided by market price — NOT the headline percentage.',
                        ],
                        'payout_ratio_percent' => ['type' => 'number'],
                        'commentary' => ['type' => 'string'],
                    ],
                ],
                'data_gaps' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Metrics that were missing and materially limited this analysis.',
                ],
            ],
            'required' => ['valuation', 'quality_score', 'financial_health_score', 'summary', 'strengths', 'concerns'],
        ];
    }
}
