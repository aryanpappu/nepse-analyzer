<?php

declare(strict_types=1);

namespace App\Services\Playbook\Backtest;

/**
 * What one side of a trade costs, in rupees.
 *
 * An interface rather than a class because the real NEPSE fee schedule is
 * still an open item on the roadmap ("exact transaction costs — tiered broker
 * commission, SEBON fee, DP charge, computed rather than typed in"). The
 * backtest injects a calculator so that when the real schedule lands, or when
 * a different broker's is needed, nothing in the simulator changes.
 *
 * Turnover is the gross consideration for that side (quantity x price), never
 * net of anything.
 */
interface TradeCostCalculator
{
    public function buyCost(float $turnover): float;

    public function sellCost(float $turnover): float;

    /**
     * The schedule in force, for the coverage/provenance block of a report —
     * a cost figure nobody can reproduce is a cost figure nobody believes.
     *
     * @return array<string, mixed>
     */
    public function describe(): array;
}
