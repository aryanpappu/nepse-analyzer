<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Enums\CompoundingBasis;
use App\Enums\PayoutHandling;
use Illuminate\Support\Carbon;

/**
 * Pure interest maths for one cash placement — no Eloquent, no config, no
 * clock. Every input is a plain value so this is fully unit-testable and the
 * only place the day-count and compounding rules are decided.
 *
 * Day-count convention: Actual/365 Fixed. Exact calendar days between two
 * dates, divided by a flat 365 — the same convention `PerformanceService`
 * already uses for the hypothetical fixed-deposit benchmark
 * (`fixedDepositValue()`: `diffInDays / 365`). Reusing it here rather than
 * inventing a second convention is what lets a real placement and the
 * benchmark's hypothetical deposit be compared like-for-like later. Actual/365
 * Fixed slightly overstates a leap year's return (366 real days over a 365-day
 * denominator) rather than the reverse, which matches how Nepali banks quote
 * a flat annual percentage without adjusting for the leap day.
 *
 * Compounding: a Nepali FD is typically a simple annual rate paid out each
 * quarter — the balance that keeps earning never grows, so that case (and any
 * placement recorded with `CompoundingBasis::Simple`) is priced with one
 * simple-interest formula over the whole period. A cumulative FD instead
 * compounds at its own period (`monthly`/`quarterly`/`semi_annually`/
 * `annually`): interest is credited back to the balance at each period
 * boundary and itself starts earning. The final, possibly partial, period
 * up to the effective end date is priced with the same exact-day formula —
 * there is no special case for "partial" vs "full" period under an exact-day
 * convention, only the day count differs.
 */
final class InterestAccrualCalculator
{
    private const DAYS_PER_YEAR = 365;

    /** Safety valve against a pathological date range producing a runaway loop. */
    private const MAX_PERIODS = 5000;

    public function accrue(
        float $principal,
        float $annualRatePercent,
        Carbon $startDate,
        Carbon $asOfDate,
        CompoundingBasis $basis,
        PayoutHandling $payout,
        ?Carbon $maturityDate = null,
        ?Carbon $closedDate = null,
    ): AccrualResult {
        $start = $startDate->copy()->startOfDay();
        $effectiveEnd = $asOfDate->copy()->startOfDay();
        $cappedAtMaturity = false;

        if ($maturityDate !== null) {
            $maturity = $maturityDate->copy()->startOfDay();

            if ($maturity->lt($effectiveEnd)) {
                $effectiveEnd = $maturity;
                $cappedAtMaturity = true;
            }
        }

        // A recorded closure (withdrawn / broken early) stops accrual on the
        // day it happened, even earlier than a still-future maturity date.
        if ($closedDate !== null) {
            $closed = $closedDate->copy()->startOfDay();

            if ($closed->lt($effectiveEnd)) {
                $effectiveEnd = $closed;
                $cappedAtMaturity = false;
            }
        }

        // Zero/negative-day edge case: the as-of date (or an earlier cap) is
        // on or before the start date — nothing has accrued yet. This also
        // covers a mis-entered maturity/closure date before the start date,
        // rather than reporting a negative interest figure.
        if ($effectiveEnd->lte($start) || $principal <= 0.0) {
            return new AccrualResult(
                principal: $principal,
                accruedInterest: 0.0,
                principalAtEnd: $principal,
                effectiveEndDate: $effectiveEnd->lt($start) ? $start->copy() : $effectiveEnd,
                daysAccrued: 0,
                isCappedAtMaturity: $cappedAtMaturity,
            );
        }

        $isSimple = $payout === PayoutHandling::PaidOut || $basis === CompoundingBasis::Simple;

        if ($isSimple) {
            $days = (int) $start->diffInDays($effectiveEnd);
            $interest = $principal * ($annualRatePercent / 100) * ($days / self::DAYS_PER_YEAR);

            return new AccrualResult(
                principal: $principal,
                accruedInterest: round($interest, 2),
                // Paid out (or a non-compounding basis): the earning balance
                // itself never grows, whatever is disbursed along the way.
                principalAtEnd: $principal,
                effectiveEndDate: $effectiveEnd,
                daysAccrued: $days,
                isCappedAtMaturity: $cappedAtMaturity,
            );
        }

        // Reinvested: compound at the placement's own period, exact days per
        // segment, so a stub final period is priced correctly rather than
        // assumed to be a full nominal period.
        $months = $basis->monthsPerPeriod() ?? 12;
        $balance = $principal;
        $cursor = $start->copy();
        $guard = 0;

        while ($cursor->lt($effectiveEnd)) {
            $next = $cursor->copy()->addMonths($months);
            $segmentEnd = $next->gt($effectiveEnd) ? $effectiveEnd->copy() : $next;
            $days = (int) $cursor->diffInDays($segmentEnd);
            $balance += $balance * ($annualRatePercent / 100) * ($days / self::DAYS_PER_YEAR);
            $cursor = $segmentEnd;

            if (++$guard > self::MAX_PERIODS) {
                break;
            }
        }

        $totalDays = (int) $start->diffInDays($effectiveEnd);

        return new AccrualResult(
            principal: $principal,
            accruedInterest: round($balance - $principal, 2),
            principalAtEnd: round($balance, 2),
            effectiveEndDate: $effectiveEnd,
            daysAccrued: $totalDays,
            isCappedAtMaturity: $cappedAtMaturity,
        );
    }
}
