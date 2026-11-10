<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

/**
 * Capital gains tax on realised round trips.
 *
 * Pure maths over plain values — no Eloquent, no config, no clock — in the
 * same shape as {@see InterestAccrualCalculator}, and for the same reason: the
 * rules are the part worth pinning down in a test, and they are only testable
 * if nothing has to be bootstrapped to reach them.
 *
 * It takes {@see ClosedTrade} objects straight from
 * `PerformanceService::closedTrades()`. That is deliberate and the whole point
 * of building here rather than starting over: the hard part of a holding-period
 * rule is matching a disposal back to the acquisition that funded it, and the
 * ledger replay already does it FIFO, with bonus units inheriting the
 * position's age rather than the award date. Re-deriving any of that would give
 * two answers to one question.
 *
 * Three decisions worth knowing:
 *
 * - **The gain is already the right tax base.** `ClosedTrade::gain` is proceeds
 *   net of sell-side fees less a cost basis with buy-side fees capitalised into
 *   it, which is how the gain on a disposal is computed for tax. Nothing is
 *   re-grossed-up here.
 * - **Losses do not offset gains.** Tax is withheld per disposal at
 *   settlement, so a losing trade reduces neither the rate nor the amount
 *   withheld on a winning one. Both figures are reported — `taxable_gain`
 *   (winners only, what tax is actually charged on) and `gross_gain` (net of
 *   losses, what the year really earned) — because quoting only one of them
 *   misleads in one direction or the other.
 * - **An unmatched disposal is excluded and named**, never rated at the
 *   short-term rate on a zero-day hold invented by the fallback.
 */
final class CapitalGainsTaxCalculator
{
    /**
     * @param  ClosedTrade  $trade  A reconstructed round trip.
     */
    public function assess(ClosedTrade $trade, CapitalGainsTaxRates $rates): CapitalGainsTaxAssessment
    {
        $days = $trade->holdingPeriodDays();
        $band = $rates->bandFor($days);
        $gross = round($trade->gain, 2);
        $assessable = $band['rate_percent'] !== null;

        // A loss is taxed at nothing — a real zero, not a missing figure. Tax
        // is charged on the gain, and there is no gain.
        $tax = $assessable
            ? round(max(0.0, $gross) * $band['rate_percent'] / 100, 2)
            : null;

        return new CapitalGainsTaxAssessment(
            symbol: $trade->symbol,
            closedOn: $trade->closedOn->toDateString(),
            openedOn: $trade->acquisitionDateKnown ? $trade->openedOn->toDateString() : null,
            daysHeld: $days,
            quantity: round($trade->quantity, 2),
            costBasis: round($trade->costBasis, 2),
            proceeds: round($trade->proceeds, 2),
            grossGain: $gross,
            rateBand: $band['band'],
            ratePercent: $band['rate_percent'],
            rateReason: $band['reason'],
            estimatedTax: $tax,
            netGain: $tax === null ? null : round($gross - $tax, 2),
            isAssessable: $assessable,
            exclusionReason: $assessable
                ? null
                : CapitalGainsTaxAssessment::EXCLUDED_NO_ACQUISITION_DATE,
        );
    }

    /**
     * @param  list<ClosedTrade>  $trades
     * @return list<CapitalGainsTaxAssessment>
     */
    public function assessAll(array $trades, CapitalGainsTaxRates $rates): array
    {
        return array_values(array_map(
            fn (ClosedTrade $trade): CapitalGainsTaxAssessment => $this->assess($trade, $rates),
            $trades,
        ));
    }

    /**
     * Roll a set of assessments up into one figure set, with its coverage.
     *
     * Coverage is not decoration. A tax total drawn from 60% of a year's
     * disposals is not 40% short of the truth by a predictable margin — the
     * missing disposals could be the whole gain — so the count, the percentage
     * and the unrated gain are all reported, and a caller that shows the total
     * without them is showing a number nobody should act on.
     *
     * @param  list<CapitalGainsTaxAssessment>  $assessments
     * @return array<string, mixed>
     */
    public function aggregate(array $assessments): array
    {
        $total = count($assessments);
        $assessable = array_values(array_filter(
            $assessments,
            static fn (CapitalGainsTaxAssessment $a): bool => $a->isAssessable,
        ));
        $excluded = array_values(array_filter(
            $assessments,
            static fn (CapitalGainsTaxAssessment $a): bool => ! $a->isAssessable,
        ));

        $grossGain = 0.0;
        $taxableGain = 0.0;
        $realisedLoss = 0.0;
        $tax = 0.0;
        $bands = [];

        foreach ($assessable as $assessment) {
            $grossGain += $assessment->grossGain;
            $tax += (float) $assessment->estimatedTax;

            if ($assessment->isGain()) {
                $taxableGain += $assessment->grossGain;
            } else {
                $realisedLoss += $assessment->grossGain;
            }

            $key = $assessment->rateBand ?? 'unbanded';
            $bands[$key] ??= [
                'band' => $key,
                'rate_percent' => $assessment->ratePercent,
                'disposals' => 0,
                'gross_gain' => 0.0,
                'taxable_gain' => 0.0,
                'estimated_tax' => 0.0,
            ];
            $bands[$key]['disposals']++;
            $bands[$key]['gross_gain'] += $assessment->grossGain;
            $bands[$key]['taxable_gain'] += max(0.0, $assessment->grossGain);
            $bands[$key]['estimated_tax'] += (float) $assessment->estimatedTax;
        }

        foreach ($bands as $key => $band) {
            $bands[$key]['gross_gain'] = round($band['gross_gain'], 2);
            $bands[$key]['taxable_gain'] = round($band['taxable_gain'], 2);
            $bands[$key]['estimated_tax'] = round($band['estimated_tax'], 2);
        }

        $hasAssessable = $assessable !== [];
        $excludedGross = array_sum(array_map(
            static fn (CapitalGainsTaxAssessment $a): float => $a->grossGain,
            $excluded,
        ));

        return [
            'disposals' => $total,
            'assessable_disposals' => count($assessable),
            'excluded_disposals' => count($excluded),
            'excluded_symbols' => array_values(array_unique(array_map(
                static fn (CapitalGainsTaxAssessment $a): string => $a->symbol,
                $excluded,
            ))),
            // The rupee size of the hole, so a reader can judge whether the
            // gap matters rather than only how many rows it spans.
            'excluded_gross_gain' => $excluded === [] ? null : round($excludedGross, 2),
            'coverage_percent' => $total === 0 ? null : round(count($assessable) / $total * 100, 2),
            'coverage_complete' => $total > 0 && $excluded === [],
            'coverage_note' => $this->coverageNote($total, count($excluded), $excludedGross),

            // Null, not zero, when nothing could be assessed at all.
            'gross_gain' => $hasAssessable ? round($grossGain, 2) : null,
            'taxable_gain' => $hasAssessable ? round($taxableGain, 2) : null,
            'realised_loss' => $hasAssessable ? round($realisedLoss, 2) : null,
            'estimated_tax' => $hasAssessable ? round($tax, 2) : null,
            'net_gain' => $hasAssessable ? round($grossGain - $tax, 2) : null,
            'effective_tax_rate_percent' => $taxableGain > 0.0
                ? round($tax / $taxableGain * 100, 4)
                : null,
            'bands' => array_values($bands),
            'loss_offset_note' => 'Tax is withheld per disposal at settlement, so a losing trade does '
                .'not reduce the tax charged on a winning one. Tax is charged on taxable_gain '
                .'(winners only); gross_gain is the same disposals net of losses.',
        ];
    }

    private function coverageNote(int $total, int $excluded, float $excludedGross): string
    {
        if ($total === 0) {
            return 'No closed round trips in this period, so there is nothing to assess — not a nil tax bill.';
        }

        if ($excluded === 0) {
            return sprintf(
                'All %d disposal%s matched to an acquisition date, so every one carries a rate.',
                $total,
                $total === 1 ? '' : 's',
            );
        }

        return sprintf(
            '%d of %d disposals could not be matched to an acquisition date and are excluded from '
                .'every figure here, together with NPR %s of gain. Their holding period is unknown, '
                .'so no rate can be applied without guessing. The total below is therefore a floor, '
                .'not an estimate of the whole liability.',
            $excluded,
            $total,
            number_format($excludedGross, 2),
        );
    }
}
