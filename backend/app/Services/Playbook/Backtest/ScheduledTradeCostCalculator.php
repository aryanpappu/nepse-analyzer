<?php

declare(strict_types=1);

namespace App\Services\Playbook\Backtest;

/**
 * Broker commission + SEBON fee + DP charge, from a {@see TradeCostSchedule}.
 *
 * Pure arithmetic over one number, so every figure in a backtest report is
 * reproducible by hand. The reason this exists at all: on NEPSE a round trip
 * costs roughly 0.6-1.2% of notional, which is the whole edge of a momentum
 * plan whose first target is +4%. An uncosted backtest does not merely flatter
 * the engine, it can invert the ranking — a grade whose average gross return
 * is +0.9% over a 6-session hold is a losing strategy once it has paid for
 * itself, and only the net column says so.
 */
final class ScheduledTradeCostCalculator implements TradeCostCalculator
{
    public function __construct(
        private readonly TradeCostSchedule $schedule,
    ) {}

    public function buyCost(float $turnover): float
    {
        return $this->side($turnover, $this->schedule->dpChargeOnBuy);
    }

    public function sellCost(float $turnover): float
    {
        return $this->side($turnover, $this->schedule->dpChargeOnSell);
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return $this->schedule->toArray();
    }

    private function side(float $turnover, bool $chargeDp): float
    {
        if ($turnover <= 0.0) {
            return 0.0;
        }

        $commission = max(
            $this->schedule->minimumCommission,
            $turnover * $this->schedule->commissionRateFor($turnover),
        );

        $sebon = $turnover * $this->schedule->sebonFeeRate;
        $dp = $chargeDp ? $this->schedule->dpChargePerSide : 0.0;

        return round($commission + $sebon + $dp, 4);
    }
}
