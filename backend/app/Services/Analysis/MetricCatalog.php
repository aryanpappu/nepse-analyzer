<?php

declare(strict_types=1);

namespace App\Services\Analysis;

/**
 * The comparison metric set, and what each one means.
 *
 * This is the single place the vocabulary is defined: the API, the head-to-head
 * table and the AI prompt all read it, so a metric added here shows up
 * everywhere at once and cannot drift between them.
 *
 * The `better` direction on several of these encodes a NEPSE convention that is
 * not universal, and each is justified where it is declared. Anything genuinely
 * contested is marked Neutral and reported without a verdict rather than scored
 * on a coin flip.
 */
final class MetricCatalog
{
    /**
     * @return list<MetricDefinition>
     */
    public static function all(): array
    {
        return [
            new MetricDefinition(
                key: 'share_capital',
                label: 'Total share capital',
                unit: MetricUnit::Rupees,
                better: MetricDirection::Lower,
                // A smaller capital base carries the same profit further per
                // share, and every future bonus issue dilutes from a lower
                // starting point. This is why NEPSE comparisons treat the
                // smaller-capital bank as the winner on an otherwise equal
                // footing — it is a dilution argument, not a size one.
                rationale: 'A smaller capital base dilutes less on each bonus issue.',
            ),
            new MetricDefinition(
                key: 'distributable_eps',
                label: 'Distributable EPS',
                unit: MetricUnit::Rupees,
                better: MetricDirection::Higher,
                rationale: 'Retained earnings per share — the ceiling on the next dividend.',
            ),
            new MetricDefinition(
                key: 'distributable_eps_yield',
                label: 'DEPS yield',
                unit: MetricUnit::Percent,
                better: MetricDirection::Higher,
                rationale: 'Accumulated distributable profit bought per rupee of price.',
            ),
            new MetricDefinition(
                key: 'eps',
                label: 'EPS (annualised)',
                unit: MetricUnit::Rupees,
                better: MetricDirection::Higher,
                rationale: 'Current earning power per share.',
            ),
            new MetricDefinition(
                key: 'pe_ratio',
                label: 'P/E ratio',
                unit: MetricUnit::Times,
                better: MetricDirection::Lower,
                rationale: 'Fewer rupees paid per rupee of earnings.',
            ),
            new MetricDefinition(
                key: 'book_value',
                label: 'Book value per share',
                unit: MetricUnit::Rupees,
                better: MetricDirection::Higher,
                rationale: 'Net assets backing each share.',
            ),
            new MetricDefinition(
                key: 'pb_ratio',
                label: 'P/B ratio',
                unit: MetricUnit::Times,
                better: MetricDirection::Lower,
                rationale: 'Fewer rupees paid per rupee of net assets.',
            ),
            new MetricDefinition(
                key: 'roe',
                label: 'Return on equity',
                unit: MetricUnit::Percent,
                better: MetricDirection::Higher,
                rationale: 'How hard shareholder capital is working.',
            ),
            new MetricDefinition(
                key: 'roa',
                label: 'Return on assets',
                unit: MetricUnit::Percent,
                better: MetricDirection::Higher,
                rationale: 'Profit generated per rupee of balance sheet.',
            ),
            new MetricDefinition(
                key: 'dividend_yield',
                label: 'Cash dividend yield',
                unit: MetricUnit::Percent,
                better: MetricDirection::Higher,
                rationale: 'Cash actually received per rupee of price.',
            ),
            new MetricDefinition(
                key: 'upside_to_52w_high_percent',
                label: 'Upside to 52-week high',
                unit: MetricUnit::Percent,
                better: MetricDirection::Higher,
                // Published comparisons quote this as a rupee gap, which is not
                // comparable between a NPR 725 scrip and a NPR 552 one. The
                // ordering is the same; the percentage is the defensible form.
                rationale: 'Room back to the 52-week high, as a share of price.',
            ),
            new MetricDefinition(
                key: 'premium_over_52w_low_percent',
                label: 'Premium over 52-week low',
                unit: MetricUnit::Percent,
                better: MetricDirection::Lower,
                rationale: 'How far the price has already run from its floor.',
            ),
            new MetricDefinition(
                key: 'npl_percent',
                label: 'Non-performing loans',
                unit: MetricUnit::Percent,
                better: MetricDirection::Lower,
                rationale: 'Credit quality. Banking and microfinance only.',
            ),
            new MetricDefinition(
                key: 'capital_adequacy_ratio',
                label: 'Capital adequacy',
                unit: MetricUnit::Percent,
                better: MetricDirection::Higher,
                rationale: 'Buffer above the regulatory minimum. Banking only.',
            ),
            new MetricDefinition(
                key: 'base_rate',
                label: 'Base rate',
                unit: MetricUnit::Percent,
                better: MetricDirection::Lower,
                rationale: 'Cost of funds; a lower base rate widens the spread. Banking only.',
            ),
            new MetricDefinition(
                key: 'last_price',
                label: 'Last traded price',
                unit: MetricUnit::Rupees,
                // Deliberately unscored. Published tables hand the win to the
                // cheaper scrip, but a share price on its own says nothing
                // about value — that is what P/E and P/B are for, and both are
                // already scored above. Scoring it would double-count price and
                // reward a low number for its own sake.
                better: MetricDirection::Neutral,
                rationale: 'Context only. Cheapness is scored through P/E and P/B.',
            ),
            new MetricDefinition(
                key: 'market_capitalization',
                label: 'Market capitalisation',
                unit: MetricUnit::Rupees,
                better: MetricDirection::Neutral,
                rationale: 'Context only. Size is neither good nor bad on its own.',
            ),
        ];
    }

    /**
     * @return array<string, MetricDefinition>
     */
    public static function keyed(): array
    {
        static $keyed = null;

        if ($keyed === null) {
            $keyed = [];

            foreach (self::all() as $metric) {
                $keyed[$metric->key] = $metric;
            }
        }

        return $keyed;
    }

    /**
     * Metrics that carry a verdict, in table order.
     *
     * @return list<MetricDefinition>
     */
    public static function scored(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (MetricDefinition $m): bool => $m->better !== MetricDirection::Neutral,
        ));
    }
}
