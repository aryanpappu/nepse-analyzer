<?php

declare(strict_types=1);

namespace App\Services\Playbook\Backtest;

/**
 * Turns simulated trades into per-grade and per-profile numbers.
 *
 * Pure and injectable, for the same reason `IndexReturnCalculator` is: the
 * maths that decides whether the engine earns its place should be provable by
 * hand in a unit test rather than inferred from a command's output.
 *
 * `summarize()` returns **null** for an empty bag rather than a zeroed
 * statistics object. A grade nothing was ever graded, or whose every plan is
 * still unresolved, has no hit rate — and a 0% hit rate printed next to a real
 * one would be read as a finding.
 */
final class BacktestStatistics
{
    /** Net-return buckets, in percent. Upper bound exclusive; the last is open. */
    private const DISTRIBUTION_EDGES = [-20.0, -10.0, -5.0, -2.0, 0.0, 2.0, 5.0, 10.0, 20.0];

    /**
     * @param  list<SimulatedTrade>  $trades  Every plan simulated for this slice, resolved or not.
     */
    public function summarize(array $trades): ?TradeStatistics
    {
        if ($trades === []) {
            return null;
        }

        $outcomes = [];

        foreach (TradeOutcome::cases() as $case) {
            $outcomes[$case->value] = 0;
        }

        $resolved = [];

        foreach ($trades as $trade) {
            $outcomes[$trade->outcome->value]++;

            if ($trade->isResolved()) {
                $resolved[] = $trade;
            }
        }

        $noFill = $outcomes[TradeOutcome::NoFill->value];
        $unresolved = $outcomes[TradeOutcome::Unresolved->value];

        if ($resolved === []) {
            // The slice exists and its plans are counted, but nothing about
            // its performance is known. Every performance field stays null.
            return new TradeStatistics(
                resolved: 0,
                noFill: $noFill,
                unresolved: $unresolved,
                outcomes: $outcomes,
                hitRatePercent: null,
                targetHitRatePercent: null,
                stopRatePercent: null,
                expiredRatePercent: null,
                averageGrossReturnPercent: null,
                averageNetReturnPercent: null,
                medianNetReturnPercent: null,
                bestNetReturnPercent: null,
                worstNetReturnPercent: null,
                stdDevNetReturnPercent: null,
                averageHoldSessions: null,
                totalCapitalDeployed: null,
                totalGrossProfit: null,
                totalNetProfit: null,
                totalCosts: null,
                costDragPercent: null,
                averageScore: $this->averageScore($trades),
                distribution: $this->emptyDistribution(),
            );
        }

        $count = count($resolved);
        $net = array_map(static fn (SimulatedTrade $t): float => (float) $t->netReturnPercent, $resolved);
        $gross = array_map(static fn (SimulatedTrade $t): float => (float) $t->grossReturnPercent, $resolved);

        $winners = count(array_filter($net, static fn (float $r): bool => $r > 0.0));
        $targets = count(array_filter($resolved, static fn (SimulatedTrade $t): bool => $t->outcome === TradeOutcome::Target));
        $stops = count(array_filter($resolved, static fn (SimulatedTrade $t): bool => $t->outcome === TradeOutcome::Stop));
        $expired = count(array_filter($resolved, static fn (SimulatedTrade $t): bool => $t->outcome === TradeOutcome::Expired));

        $deployed = 0.0;
        $grossProfit = 0.0;
        $netProfit = 0.0;
        $costs = 0.0;
        $holdSessions = 0;

        foreach ($resolved as $trade) {
            $deployed += (float) $trade->capitalDeployed();
            $grossProfit += (float) $trade->grossProfit;
            $netProfit += (float) $trade->netProfit;
            $costs += (float) $trade->totalCosts();
            $holdSessions += (int) $trade->holdSessions;
        }

        return new TradeStatistics(
            resolved: $count,
            noFill: $noFill,
            unresolved: $unresolved,
            outcomes: $outcomes,
            hitRatePercent: round($winners / $count * 100, 2),
            targetHitRatePercent: round($targets / $count * 100, 2),
            stopRatePercent: round($stops / $count * 100, 2),
            expiredRatePercent: round($expired / $count * 100, 2),
            averageGrossReturnPercent: round(array_sum($gross) / $count, 4),
            averageNetReturnPercent: round(array_sum($net) / $count, 4),
            medianNetReturnPercent: $this->median($net),
            bestNetReturnPercent: round(max($net), 4),
            worstNetReturnPercent: round(min($net), 4),
            stdDevNetReturnPercent: $this->stdDev($net),
            averageHoldSessions: round($holdSessions / $count, 2),
            totalCapitalDeployed: round($deployed, 2),
            totalGrossProfit: round($grossProfit, 2),
            totalNetProfit: round($netProfit, 2),
            totalCosts: round($costs, 2),
            costDragPercent: $deployed <= 0.0 ? null : round($costs / $deployed * 100, 4),
            averageScore: $this->averageScore($trades),
            distribution: $this->distribute($net),
        );
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return round($n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2, 4);
    }

    /**
     * Population standard deviation — the sample here *is* the population of
     * trades the strategy took. Null below two trades, where dispersion is
     * undefined rather than zero.
     *
     * @param  list<float>  $values
     */
    private function stdDev(array $values): ?float
    {
        $n = count($values);

        if ($n < 2) {
            return null;
        }

        $mean = array_sum($values) / $n;
        $sum = 0.0;

        foreach ($values as $value) {
            $sum += ($value - $mean) ** 2;
        }

        return round(sqrt($sum / $n), 4);
    }

    /**
     * @param  list<SimulatedTrade>  $trades
     */
    private function averageScore(array $trades): ?float
    {
        if ($trades === []) {
            return null;
        }

        $scores = array_map(static fn (SimulatedTrade $t): float => $t->subject->score, $trades);

        return round(array_sum($scores) / count($scores), 2);
    }

    /**
     * @param  list<float>  $net
     * @return array<string, int>
     */
    private function distribute(array $net): array
    {
        $buckets = $this->emptyDistribution();
        $labels = array_keys($buckets);

        foreach ($net as $value) {
            $index = count(self::DISTRIBUTION_EDGES);

            foreach (self::DISTRIBUTION_EDGES as $i => $edge) {
                if ($value < $edge) {
                    $index = $i;

                    break;
                }
            }

            $buckets[$labels[$index]]++;
        }

        return $buckets;
    }

    /**
     * @return array<string, int>
     */
    private function emptyDistribution(): array
    {
        $buckets = [sprintf('< %.0f%%', self::DISTRIBUTION_EDGES[0]) => 0];
        $edges = self::DISTRIBUTION_EDGES;
        $last = count($edges) - 1;

        for ($i = 0; $i < $last; $i++) {
            $buckets[sprintf('%.0f to %.0f%%', $edges[$i], $edges[$i + 1])] = 0;
        }

        $buckets[sprintf('>= %.0f%%', $edges[$last])] = 0;

        return $buckets;
    }
}
