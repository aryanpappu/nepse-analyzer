<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CashPlacement;
use App\Services\Portfolio\CashPlacementService;
use App\Services\Portfolio\InterestAccrualCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CashPlacementService::summarize() over an in-memory collection of
 * unsaved CashPlacement models — no query, no database.
 *
 * The app is booted (Tests\TestCase, same as PerformanceServiceTest) only
 * because Eloquent's attribute casts (enums, dates) need a connection
 * resolver to exist; no query is ever run against it.
 */
final class CashPlacementServiceTest extends TestCase
{
    private function service(): CashPlacementService
    {
        return new CashPlacementService(new InterestAccrualCalculator);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function placement(array $attributes): CashPlacement
    {
        return new CashPlacement($attributes);
    }

    #[Test]
    public function summary_scopes_the_weighted_rate_to_active_interest_bearing_placements_only(): void
    {
        $asOf = Carbon::parse('2026-01-01');

        // Still earning at its stated rate, far from maturity.
        $active1 = $this->placement([
            'principal' => 100000, 'interest_rate' => 8, 'status' => 'active',
            'start_date' => '2025-01-01', 'maturity_date' => '2027-01-01',
            'compounding_basis' => 'simple', 'payout' => 'paid_out', 'kind' => 'fixed_deposit',
        ]);

        // Still earning, matures inside the 30-day "soon" window.
        $active2 = $this->placement([
            'principal' => 50000, 'interest_rate' => 10, 'status' => 'active',
            'start_date' => '2025-06-01', 'maturity_date' => '2026-01-20',
            'compounding_basis' => 'simple', 'payout' => 'paid_out', 'kind' => 'fixed_deposit',
        ]);

        // Already matured (by status) before the as-of date.
        $matured = $this->placement([
            'principal' => 20000, 'interest_rate' => 9, 'status' => 'matured',
            'start_date' => '2024-01-01', 'maturity_date' => '2025-06-01',
            'compounding_basis' => 'simple', 'payout' => 'paid_out', 'kind' => 'fixed_deposit',
        ]);

        // Withdrawn early — capital is gone, so it drops out of total principal
        // and the live-rate average, even though it's counted as "matured"
        // (closed) for maturity-awareness purposes.
        $withdrawn = $this->placement([
            'principal' => 30000, 'interest_rate' => 7, 'status' => 'withdrawn',
            'start_date' => '2023-01-01', 'maturity_date' => '2024-01-01',
            'closed_on' => '2023-11-01',
            'compounding_basis' => 'simple', 'payout' => 'paid_out', 'kind' => 'fixed_deposit',
        ]);

        $placements = new Collection([$active1, $active2, $matured, $withdrawn]);

        $summary = $this->service()->summarize($placements, $asOf);

        self::assertSame(4, $summary['count']);

        // Withdrawn's principal is excluded; the other three are not.
        self::assertSame(170000.0, $summary['total_principal']);

        // (100000*8 + 50000*10) / (100000+50000) = 8.667
        self::assertSame(8.667, $summary['weighted_average_rate']);

        // matured + withdrawn = 2; the two still-active ones are not matured.
        self::assertSame(2, $summary['matured_count']);

        // Only $active2 falls inside the 30-day maturing-soon window.
        self::assertSame(1, $summary['maturing_soon_count']);
        self::assertSame(30, $summary['maturing_soon_within_days']);

        // Internal consistency: the reported total is exactly the sum of
        // what accrualFor() computes for each placement individually — the
        // interest maths itself is exercised by InterestAccrualCalculatorTest.
        $expectedAccrued = $placements->sum(
            fn (CashPlacement $p): float => $this->service()->accrualFor($p, $asOf)->accruedInterest
        );
        self::assertSame(round($expectedAccrued, 2), $summary['total_accrued_interest']);
    }

    #[Test]
    public function an_empty_set_reports_zeroes_and_a_null_rate_rather_than_dividing_by_zero(): void
    {
        $summary = $this->service()->summarize(new Collection, Carbon::parse('2026-01-01'));

        self::assertSame(0, $summary['count']);
        self::assertSame(0.0, $summary['total_principal']);
        self::assertSame(0.0, $summary['total_accrued_interest']);
        self::assertNull($summary['weighted_average_rate']);
    }
}
