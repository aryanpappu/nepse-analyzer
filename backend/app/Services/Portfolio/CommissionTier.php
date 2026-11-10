<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

/**
 * One step of a broker's commission schedule.
 *
 * NEPSE broker commission is not a single percentage — the rate steps *down*
 * as the transaction gets larger, so which tier a trade lands in changes the
 * cost materially. A tier is a half-open turnover band `(min, max]` carrying
 * one rate; `max` of null is the open-ended top tier.
 *
 * Half-open on the lower side and inclusive on the upper is deliberate: the
 * published schedule reads "up to Rs 50,000" then "Rs 50,001 to Rs 500,000",
 * so a turnover of exactly 50,000 belongs to the first tier and 50,000.01 to
 * the second. Getting that inclusive/exclusive edge wrong is a silent 0.03%
 * error on every trade that lands on a round number, which is most of them.
 */
final class CommissionTier
{
    public function __construct(
        /** Exclusive lower bound — turnover must be strictly greater than this. */
        public readonly float $minTurnover,
        /** Inclusive upper bound; null for the open-ended top tier. */
        public readonly ?float $maxTurnover,
        public readonly float $ratePercent,
        public readonly string $label,
    ) {}

    public function contains(float $turnover): bool
    {
        if ($turnover <= $this->minTurnover) {
            return false;
        }

        return $this->maxTurnover === null || $turnover <= $this->maxTurnover;
    }

    /**
     * How much of $turnover falls inside this tier's band.
     *
     * Only used under the `progressive` application mode, where each slice of
     * turnover is charged at its own tier's rate rather than the whole amount
     * being charged at the rate of the tier it happens to land in.
     */
    public function overlapWith(float $turnover): float
    {
        if ($turnover <= $this->minTurnover) {
            return 0.0;
        }

        $ceiling = $this->maxTurnover === null ? $turnover : min($turnover, $this->maxTurnover);

        return max(0.0, $ceiling - $this->minTurnover);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'above' => $this->minTurnover,
            'up_to' => $this->maxTurnover,
            'rate_percent' => $this->ratePercent,
        ];
    }
}
