<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use Illuminate\Support\Carbon;

/**
 * What one cash placement has earned between its start and some end date.
 *
 * Produced by {@see InterestAccrualCalculator}, which never touches Eloquent —
 * this is the plain-value handoff back to the caller (service, controller, or
 * command) that does.
 */
final class AccrualResult
{
    public function __construct(
        public readonly float $principal,
        public readonly float $accruedInterest,
        /** Principal plus accrued interest when reinvested; equals principal when paid out. */
        public readonly float $principalAtEnd,
        public readonly Carbon $effectiveEndDate,
        /** Exact calendar days actually accrued (Actual/365 Fixed day count). */
        public readonly int $daysAccrued,
        /** Whether accrual stopped at the placement's maturity rather than the requested as-of date. */
        public readonly bool $isCappedAtMaturity,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'principal' => $this->principal,
            'accrued_interest' => $this->accruedInterest,
            'principal_at_end' => $this->principalAtEnd,
            'effective_end_date' => $this->effectiveEndDate->toDateString(),
            'days_accrued' => $this->daysAccrued,
            'is_capped_at_maturity' => $this->isCappedAtMaturity,
        ];
    }
}
