<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

/**
 * What one side of a trade actually costs, itemised.
 *
 * Produced by {@see TransactionCostCalculator}. Every field a caller might
 * otherwise re-derive is present, including which commission tier applied and
 * under which schedule — a total with no tier attached is a number nobody can
 * check against a broker note.
 *
 * `total` is always a positive cost. Which direction it moves cash is the
 * side's business: {@see netCashFlow()} signs it.
 */
final class TransactionCostBreakdown
{
    public function __construct(
        public readonly string $side,
        public readonly string $instrumentClass,
        public readonly float $quantity,
        public readonly float $price,
        public readonly float $turnover,
        public readonly float $commission,
        public readonly float $sebonFee,
        public readonly float $dpCharge,
        public readonly float $total,
        public readonly ?string $tierLabel,
        public readonly ?float $tierRatePercent,
        public readonly string $application,
        public readonly string $scheduleEffectiveFrom,
        public readonly string $scheduleSource,
        /** Whether the rupee floor, rather than the tier rate, set the commission. */
        public readonly bool $minimumCommissionApplied,
        /** Non-fatal notes: an unmapped instrument class, a schedule with no tiers. */
        public readonly ?string $note = null,
    ) {}

    /**
     * Cost as a percentage of turnover — the figure that decides whether a
     * marginal trade is worth taking. Null on a zero turnover rather than 0.0,
     * because "no cost" and "no trade" are different answers.
     */
    public function costPercent(): ?float
    {
        return $this->turnover > 0.0 ? round($this->total / $this->turnover * 100, 4) : null;
    }

    /**
     * Cash the trade moves, costs included. Negative on a buy.
     */
    public function netCashFlow(): float
    {
        return $this->side === TransactionCostCalculator::SIDE_SELL
            ? round($this->turnover - $this->total, 2)
            : round(-($this->turnover + $this->total), 2);
    }

    /**
     * Per-unit price once costs are folded in — the break-even price on a buy,
     * the realised price on a sell. Null on a zero quantity.
     */
    public function effectivePricePerUnit(): ?float
    {
        if ($this->quantity <= 0.0) {
            return null;
        }

        return $this->side === TransactionCostCalculator::SIDE_SELL
            ? round(($this->turnover - $this->total) / $this->quantity, 4)
            : round(($this->turnover + $this->total) / $this->quantity, 4);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'side' => $this->side,
            'instrument_class' => $this->instrumentClass,
            'quantity' => $this->quantity,
            'price' => $this->price,
            'turnover' => $this->turnover,
            'commission' => $this->commission,
            'sebon_fee' => $this->sebonFee,
            'dp_charge' => $this->dpCharge,
            'total' => $this->total,
            'cost_percent' => $this->costPercent(),
            'net_cash_flow' => $this->netCashFlow(),
            'effective_price_per_unit' => $this->effectivePricePerUnit(),
            'tier_label' => $this->tierLabel,
            'tier_rate_percent' => $this->tierRatePercent,
            'application' => $this->application,
            'schedule_effective_from' => $this->scheduleEffectiveFrom,
            'schedule_source' => $this->scheduleSource,
            'minimum_commission_applied' => $this->minimumCommissionApplied,
            'note' => $this->note,
        ];
    }
}
