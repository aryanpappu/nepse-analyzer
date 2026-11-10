<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\CompoundingBasis;
use App\Enums\PayoutHandling;
use App\Services\Portfolio\InterestAccrualCalculator;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pure interest maths, no database and no app boot — {@see
 * InterestAccrualCalculator} depends on nothing but Carbon, so plain PHPUnit
 * TestCase is enough (unlike PerformanceServiceTest, which needs Tests\TestCase
 * only for Eloquent's attribute-cast resolver).
 *
 * Day-count convention under test throughout: Actual/365 Fixed — exact
 * calendar days between two dates divided by a flat 365, the same convention
 * PerformanceService::fixedDepositValue() already uses for the hypothetical
 * FD benchmark.
 */
final class InterestAccrualCalculatorTest extends TestCase
{
    private function calculator(): InterestAccrualCalculator
    {
        return new InterestAccrualCalculator;
    }

    #[Test]
    public function simple_interest_over_two_years_undercounts_against_annual_compounding(): void
    {
        // 10% for two ordinary (non-leap) years = 730 exact days.
        $start = Carbon::parse('2021-01-01');
        $asOf = Carbon::parse('2023-01-01');

        $simple = $this->calculator()->accrue(
            100000.0, 10.0, $start->copy(), $asOf->copy(),
            CompoundingBasis::Annually, PayoutHandling::PaidOut,
        );

        $compound = $this->calculator()->accrue(
            100000.0, 10.0, $start->copy(), $asOf->copy(),
            CompoundingBasis::Annually, PayoutHandling::Reinvested,
        );

        // Simple: 100000 * 0.10 * 730/365 = 20000.00 exactly.
        self::assertSame(20000.0, $simple->accruedInterest);
        self::assertSame(100000.0, $simple->principalAtEnd);

        // Compound annually for 2 full years: 100000 * 1.10 * 1.10 = 121000,
        // i.e. 21000.00 interest — the textbook "10% compounded annually for
        // two years is 21%, not 20%" result.
        self::assertSame(21000.0, $compound->accruedInterest);
        self::assertSame(121000.0, $compound->principalAtEnd);

        self::assertGreaterThan($simple->accruedInterest, $compound->accruedInterest);
    }

    #[Test]
    public function part_year_accrual_uses_exact_days(): void
    {
        // Jan 1 -> Apr 1 in a non-leap year is exactly 90 days.
        $start = Carbon::parse('2025-01-01');
        $asOf = Carbon::parse('2025-04-01');

        $result = $this->calculator()->accrue(
            100000.0, 10.0, $start, $asOf,
            CompoundingBasis::Simple, PayoutHandling::PaidOut,
        );

        self::assertSame(90, $result->daysAccrued);
        // 100000 * 0.10 * 90/365 = 2465.753... -> 2465.75
        self::assertSame(2465.75, $result->accruedInterest);
    }

    #[Test]
    public function leap_year_accrues_one_extra_days_worth_of_interest(): void
    {
        // 2024 is a leap year: Jan 1 2024 -> Jan 1 2025 is 366 real days.
        $start = Carbon::parse('2024-01-01');
        $asOf = Carbon::parse('2025-01-01');

        $result = $this->calculator()->accrue(
            100000.0, 10.0, $start, $asOf,
            CompoundingBasis::Simple, PayoutHandling::PaidOut,
        );

        self::assertSame(366, $result->daysAccrued);
        // 100000 * 0.10 * 366/365 = 10027.397... -> 10027.40, i.e. slightly
        // more than a flat 10000 — Actual/365 Fixed does not discount the leap
        // day, matching how the rate is quoted (a flat annual percentage).
        self::assertSame(10027.4, $result->accruedInterest);
        self::assertGreaterThan(10000.0, $result->accruedInterest);
    }

    #[Test]
    public function maturity_mid_period_caps_accrual_exactly_where_an_uncapped_run_would_stop(): void
    {
        $start = Carbon::parse('2025-01-01');
        $maturity = Carbon::parse('2025-05-15'); // inside the second quarterly period
        $farAsOf = Carbon::parse('2025-12-31'); // well past maturity

        $capped = $this->calculator()->accrue(
            100000.0, 8.0, $start->copy(), $farAsOf,
            CompoundingBasis::Quarterly, PayoutHandling::Reinvested,
            maturityDate: $maturity->copy(),
        );

        // Running the same calculator with the maturity date as the as-of
        // date directly (no cap in play) must land on the exact same number —
        // capping should behave as if the placement simply ended there.
        $uncappedToMaturity = $this->calculator()->accrue(
            100000.0, 8.0, $start->copy(), $maturity->copy(),
            CompoundingBasis::Quarterly, PayoutHandling::Reinvested,
        );

        self::assertTrue($capped->isCappedAtMaturity);
        self::assertFalse($uncappedToMaturity->isCappedAtMaturity);
        self::assertSame('2025-05-15', $capped->effectiveEndDate->toDateString());
        self::assertSame($uncappedToMaturity->daysAccrued, $capped->daysAccrued);
        self::assertSame($uncappedToMaturity->accruedInterest, $capped->accruedInterest);
        self::assertSame($uncappedToMaturity->principalAtEnd, $capped->principalAtEnd);

        // And it must actually have stopped short of running the full year.
        self::assertLessThan(365, $capped->daysAccrued);
    }

    #[Test]
    public function as_of_before_start_accrues_nothing_rather_than_a_negative_figure(): void
    {
        $start = Carbon::parse('2025-06-01');
        $asOf = Carbon::parse('2025-01-01'); // before the placement even started

        $result = $this->calculator()->accrue(
            100000.0, 8.0, $start, $asOf,
            CompoundingBasis::Quarterly, PayoutHandling::Reinvested,
        );

        self::assertSame(0, $result->daysAccrued);
        self::assertSame(0.0, $result->accruedInterest);
        self::assertSame(100000.0, $result->principalAtEnd);
        self::assertSame('2025-06-01', $result->effectiveEndDate->toDateString());
    }

    #[Test]
    public function same_day_start_and_as_of_accrues_nothing(): void
    {
        $day = Carbon::parse('2025-06-01');

        $result = $this->calculator()->accrue(
            100000.0, 8.0, $day->copy(), $day->copy(),
            CompoundingBasis::Simple, PayoutHandling::PaidOut,
        );

        self::assertSame(0, $result->daysAccrued);
        self::assertSame(0.0, $result->accruedInterest);
    }

    #[Test]
    public function paid_out_interest_never_compounds_regardless_of_the_stated_basis(): void
    {
        // A Nepali FD "simple annual rate, quarterly payout" case: even
        // though a compounding basis is nominally quarterly, paid-out
        // interest never grows the earning balance.
        $start = Carbon::parse('2025-01-01');
        $asOf = Carbon::parse('2026-01-01');

        $result = $this->calculator()->accrue(
            100000.0, 8.0, $start, $asOf,
            CompoundingBasis::Quarterly, PayoutHandling::PaidOut,
        );

        self::assertSame(365, $result->daysAccrued);
        self::assertSame(8000.0, $result->accruedInterest);
        self::assertSame(100000.0, $result->principalAtEnd);
    }
}
