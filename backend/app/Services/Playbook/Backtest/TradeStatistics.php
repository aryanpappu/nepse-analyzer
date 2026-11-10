<?php

declare(strict_types=1);

namespace App\Services\Playbook\Backtest;

/**
 * What a bag of simulated trades adds up to.
 *
 * Two hit rates are reported on purpose. `targetHitRatePercent` says how often
 * the plan worked as written; `hitRatePercent` says how often it made money
 * after costs, which is not the same question — an expired trade can close
 * green and a target hit on a tiny momentum band can close red once fees are
 * paid. Reporting only one of them is how a backtest ends up flattering the
 * engine.
 */
final class TradeStatistics
{
    /**
     * @param  array<string, int>  $outcomes  Outcome value => count, over every simulated plan.
     * @param  array<string, int>  $distribution  Net-return bucket label => count, over resolved trades.
     */
    public function __construct(
        public readonly int $resolved,
        public readonly int $noFill,
        public readonly int $unresolved,
        public readonly array $outcomes,
        public readonly ?float $hitRatePercent,
        public readonly ?float $targetHitRatePercent,
        public readonly ?float $stopRatePercent,
        public readonly ?float $expiredRatePercent,
        public readonly ?float $averageGrossReturnPercent,
        public readonly ?float $averageNetReturnPercent,
        public readonly ?float $medianNetReturnPercent,
        public readonly ?float $bestNetReturnPercent,
        public readonly ?float $worstNetReturnPercent,
        public readonly ?float $stdDevNetReturnPercent,
        public readonly ?float $averageHoldSessions,
        public readonly ?float $totalCapitalDeployed,
        public readonly ?float $totalGrossProfit,
        public readonly ?float $totalNetProfit,
        public readonly ?float $totalCosts,
        public readonly ?float $costDragPercent,
        public readonly ?float $averageScore,
        public readonly array $distribution,
    ) {}

    /**
     * How many plans were simulated in total, resolved or not — the
     * denominator of the resolution rate, and the number that says whether a
     * hit rate is worth reading.
     */
    public function planned(): int
    {
        return array_sum($this->outcomes);
    }

    public function resolutionPercent(): ?float
    {
        $planned = $this->planned();

        return $planned === 0 ? null : round($this->resolved / $planned * 100, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'planned' => $this->planned(),
            'resolved' => $this->resolved,
            'no_fill' => $this->noFill,
            'unresolved' => $this->unresolved,
            'resolution_percent' => $this->resolutionPercent(),
            'outcomes' => $this->outcomes,
            'hit_rate_percent' => $this->hitRatePercent,
            'target_hit_rate_percent' => $this->targetHitRatePercent,
            'stop_rate_percent' => $this->stopRatePercent,
            'expired_rate_percent' => $this->expiredRatePercent,
            'average_gross_return_percent' => $this->averageGrossReturnPercent,
            'average_net_return_percent' => $this->averageNetReturnPercent,
            'median_net_return_percent' => $this->medianNetReturnPercent,
            'best_net_return_percent' => $this->bestNetReturnPercent,
            'worst_net_return_percent' => $this->worstNetReturnPercent,
            'stddev_net_return_percent' => $this->stdDevNetReturnPercent,
            'average_hold_sessions' => $this->averageHoldSessions,
            'total_capital_deployed' => $this->totalCapitalDeployed,
            'total_gross_profit' => $this->totalGrossProfit,
            'total_net_profit' => $this->totalNetProfit,
            'total_costs' => $this->totalCosts,
            'cost_drag_percent' => $this->costDragPercent,
            'average_score' => $this->averageScore,
            'distribution' => $this->distribution,
        ];
    }
}
