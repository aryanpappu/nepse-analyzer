<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\CashPlacementKind;
use App\Enums\CashPlacementStatus;
use App\Enums\CompoundingBasis;
use App\Enums\PayoutHandling;
use App\Services\Portfolio\PlacementValuator;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Placement valuation as pure arithmetic over plain arrays — no database, no
 * app boot, in the same shape as InterestAccrualCalculatorTest and
 * IndexReturnCalculatorTest.
 *
 * The tests that matter most here are the double-counting ones. Opening a
 * placement does not have to debit a cash account, so a placement recorded
 * without a settlement still has its principal sitting in `bank_balance`;
 * counting it as a placement too would report the same 500,000 twice.
 */
final class PlacementValuatorTest extends TestCase
{
    private function valuator(): PlacementValuator
    {
        return new PlacementValuator;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function placement(array $overrides = []): array
    {
        return $overrides + [
            'id' => 1,
            'label' => 'Nabil FD-001',
            'kind' => CashPlacementKind::FixedDeposit,
            'status' => CashPlacementStatus::Active,
            'principal' => 500000.0,
            'interest_rate' => 10.0,
            'start_date' => Carbon::parse('2025-01-01'),
            'maturity_date' => Carbon::parse('2026-01-01'),
            'closed_on' => null,
            'compounding_basis' => CompoundingBasis::Simple,
            'payout' => PayoutHandling::PaidOut,
            'cash_settled' => true,
        ];
    }

    // ------------------------------------------------------------------
    // No double counting
    // ------------------------------------------------------------------

    #[Test]
    public function a_settled_placement_counts_toward_net_worth(): void
    {
        $result = $this->valuator()->valueOn(
            [$this->placement(['cash_settled' => true])],
            Carbon::parse('2025-07-02'),
        );

        self::assertSame(500000.0, $result->netWorthContribution);
        self::assertSame(1, $result->settledCount);
        self::assertSame(0.0, $result->unsettledValue);
        self::assertSame(0, $result->unsettledCount);
        self::assertSame([], $result->excluded);
    }

    #[Test]
    public function an_unsettled_placement_is_excluded_from_net_worth_because_its_principal_is_still_idle_cash(): void
    {
        $result = $this->valuator()->valueOn(
            [$this->placement(['cash_settled' => false])],
            Carbon::parse('2025-07-02'),
        );

        // The money never left bank_balance, so investableCash() already
        // counts it. Adding it here would report the same rupees twice.
        self::assertSame(0.0, $result->netWorthContribution);
        self::assertSame(0, $result->settledCount);
        self::assertSame(500000.0, $result->unsettledValue);
        self::assertSame(1, $result->unsettledCount);

        // Excluded, but named with a reason — never silently dropped.
        self::assertCount(1, $result->excluded);
        self::assertSame('Nabil FD-001', $result->excluded[0]['label']);
        self::assertStringContainsString('counted as idle cash', $result->excluded[0]['reason']);
        self::assertStringContainsString('NOT added to net worth', (string) $result->note());
    }

    #[Test]
    public function a_mix_of_settled_and_unsettled_placements_never_totals_more_than_the_settled_ones(): void
    {
        $result = $this->valuator()->valueOn([
            $this->placement(['id' => 1, 'cash_settled' => true, 'principal' => 500000.0]),
            $this->placement(['id' => 2, 'label' => 'NIC FD', 'cash_settled' => false, 'principal' => 300000.0]),
        ], Carbon::parse('2025-07-02'));

        self::assertSame(500000.0, $result->netWorthContribution);
        self::assertSame(300000.0, $result->unsettledValue);
        self::assertSame(800000.0, $result->principalPlaced);
    }

    #[Test]
    public function paid_out_interest_is_never_added_to_the_placement_value(): void
    {
        // Paid out: the interest was disbursed to the investor during the
        // term and is in the cash ledger. Value stays at principal, and the
        // interest is reported beside it as income.
        $result = $this->valuator()->valueOn(
            [$this->placement(['payout' => PayoutHandling::PaidOut])],
            Carbon::parse('2025-07-02'),
        );

        self::assertSame(500000.0, $result->netWorthContribution);
        self::assertGreaterThan(0.0, $result->accruedInterestOpen);
    }

    #[Test]
    public function reinvested_interest_is_inside_the_value_and_not_added_a_second_time(): void
    {
        $result = $this->valuator()->valueOn([
            $this->placement([
                'payout' => PayoutHandling::Reinvested,
                'compounding_basis' => CompoundingBasis::Quarterly,
            ]),
        ], Carbon::parse('2025-07-02'));

        // 182 days at 10% quarterly-compounded on 500,000 is a little over
        // 25,000 — the value is principal + that, once.
        self::assertGreaterThan(500000.0, $result->netWorthContribution);
        self::assertSame(
            round(500000.0 + $result->accruedInterestOpen, 2),
            round($result->netWorthContribution, 2),
        );
    }

    #[Test]
    public function pledged_shares_are_not_valued_as_cash_because_the_shares_are_already_marked_with_the_holdings(): void
    {
        $result = $this->valuator()->valueOn([
            $this->placement(['kind' => CashPlacementKind::PledgedShares]),
        ], Carbon::parse('2025-07-02'));

        self::assertSame(0.0, $result->netWorthContribution);
        self::assertSame(0.0, $result->unsettledValue);
        self::assertStringContainsString('not an interest-bearing cash placement', $result->excluded[0]['reason']);
    }

    // ------------------------------------------------------------------
    // Valuation at a past date
    // ------------------------------------------------------------------

    #[Test]
    public function a_placement_is_valued_at_the_interest_accrued_to_that_date_not_to_today(): void
    {
        // 500,000 at 10% simple, 2025-01-01 to 2025-07-01 = 181 days.
        // 500000 * 0.10 * 181/365 = 24,794.52
        $result = $this->valuator()->valueOn(
            [$this->placement(['payout' => PayoutHandling::Reinvested, 'compounding_basis' => CompoundingBasis::Simple])],
            Carbon::parse('2025-07-01'),
        );

        self::assertSame(24794.52, $result->accruedInterestOpen);
        self::assertSame('2025-07-01', $result->asOf);
        self::assertSame(181, $result->placements[0]['days_accrued']);
    }

    #[Test]
    public function a_placement_is_worth_nothing_before_it_started(): void
    {
        $result = $this->valuator()->valueOn(
            [$this->placement()],
            Carbon::parse('2024-12-31'),
        );

        self::assertSame(0.0, $result->netWorthContribution);
        self::assertSame(0.0, $result->accruedInterestOpen);
        self::assertSame(PlacementValuator::STATE_NOT_STARTED, $result->placements[0]['state']);
    }

    #[Test]
    public function accrual_stops_at_maturity_so_a_date_after_maturity_reports_the_matured_value(): void
    {
        // Matures 2026-01-01 but not yet withdrawn: the money is still
        // nominally placed, and interest stopped on the maturity date.
        $result = $this->valuator()->valueOn(
            [$this->placement(['status' => CashPlacementStatus::Matured])],
            Carbon::parse('2026-06-30'),
        );

        // 2025-01-01 to 2026-01-01 is 365 days: exactly one year at 10%.
        self::assertSame(50000.0, $result->accruedInterestOpen);
        self::assertSame(365, $result->placements[0]['days_accrued']);
        self::assertTrue($result->placements[0]['is_capped_at_maturity']);
        self::assertSame(PlacementValuator::STATE_MATURED, $result->placements[0]['state']);
        self::assertSame(500000.0, $result->netWorthContribution);
    }

    #[Test]
    public function a_withdrawn_placement_is_worth_nothing_on_and_after_its_closure_date(): void
    {
        $placement = $this->placement([
            'status' => CashPlacementStatus::Withdrawn,
            'closed_on' => Carbon::parse('2025-07-01'),
        ]);

        // The withdrawal put the money back into the cash ledger, so valuing
        // the placement as well would double-count from the other direction.
        $after = $this->valuator()->valueOn([$placement], Carbon::parse('2025-08-01'));
        self::assertSame(0.0, $after->netWorthContribution);
        self::assertSame(PlacementValuator::STATE_CLOSED, $after->placements[0]['state']);
        self::assertSame(0.0, $after->placements[0]['value']);
        // Interest it earned while it was open is still reported as income.
        self::assertSame(24794.52, $after->accruedInterestClosed);
        self::assertSame(0.0, $after->accruedInterestOpen);

        // Mid-term, before the closure, it was worth its principal.
        $before = $this->valuator()->valueOn([$placement], Carbon::parse('2025-04-01'));
        self::assertSame(500000.0, $before->netWorthContribution);
        self::assertSame(PlacementValuator::STATE_ACTIVE, $before->placements[0]['state']);
    }

    #[Test]
    public function a_withdrawal_with_no_closure_date_is_excluded_and_named_rather_than_valued_at_a_guess(): void
    {
        $result = $this->valuator()->valueOn([
            $this->placement(['status' => CashPlacementStatus::Withdrawn, 'closed_on' => null]),
        ], Carbon::parse('2025-07-02'));

        self::assertSame(0.0, $result->netWorthContribution);
        self::assertNull($result->placements[0]['value']);
        self::assertSame(PlacementValuator::STATE_UNDATED_EXIT, $result->placements[0]['state']);
        self::assertStringContainsString('cannot be dated', $result->excluded[0]['reason']);
    }

    #[Test]
    public function no_placements_reports_zero_and_no_note_rather_than_inventing_one(): void
    {
        $result = $this->valuator()->valueOn([], Carbon::parse('2025-07-02'));

        self::assertSame(0, $result->count);
        self::assertSame(0.0, $result->netWorthContribution);
        self::assertNull($result->note());
    }
}
