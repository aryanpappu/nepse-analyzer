<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Portfolio\ClosedTrade;
use App\Services\Portfolio\TransactionCostCalculator;
use App\Services\Portfolio\TransactionCostSchedule;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every number here is worked out by hand from the schedule under test, so a
 * failure points at the arithmetic rather than at a fixture.
 *
 * No database and no config: the calculator takes its schedules as a
 * constructor argument, which is the whole reason it can be pinned down like
 * this. The app is booted only for Carbon's container-bound helpers.
 */
final class TransactionCostCalculatorTest extends TestCase
{
    private function calculator(): TransactionCostCalculator
    {
        return new TransactionCostCalculator;
    }

    // ------------------------------------------------------------------
    // Commission tiers
    // ------------------------------------------------------------------

    #[Test]
    public function it_charges_the_first_tier_rate_on_a_small_trade(): void
    {
        // 10 x NPR 1,000 = 10,000 turnover, first tier at 0.36%.
        $cost = $this->calculator()->compute(TransactionCostCalculator::SIDE_BUY, 10, 1000);

        $this->assertSame(10_000.0, $cost->turnover);
        $this->assertSame(36.0, $cost->commission);
        $this->assertSame(0.36, $cost->tierRatePercent);
        $this->assertSame('up to NPR 50,000', $cost->tierLabel);
        // 10,000 x 0.015%
        $this->assertSame(1.5, $cost->sebonFee);
        $this->assertSame(25.0, $cost->dpCharge);
        $this->assertSame(62.5, $cost->total);
    }

    #[Test]
    public function it_charges_each_tier_at_its_own_rate(): void
    {
        $calculator = $this->calculator();

        // One turnover comfortably inside each of the five bands.
        $cases = [
            [40_000.0, 0.36],
            [400_000.0, 0.33],
            [1_500_000.0, 0.31],
            [5_000_000.0, 0.27],
            [20_000_000.0, 0.24],
        ];

        foreach ($cases as [$turnover, $rate]) {
            $cost = $calculator->compute(TransactionCostCalculator::SIDE_BUY, 1, $turnover);

            $this->assertSame($rate, $cost->tierRatePercent, "tier rate for turnover {$turnover}");
            $this->assertSame(
                round($turnover * $rate / 100, 2),
                $cost->commission,
                "commission for turnover {$turnover}",
            );
        }
    }

    #[Test]
    public function the_tier_boundary_is_inclusive_of_its_upper_limit(): void
    {
        $calculator = $this->calculator();

        // Exactly 50,000 is still "up to NPR 50,000" — the published schedule
        // reads "up to 50,000" then "50,001 to 500,000", so a round number
        // belongs to the cheaper-sounding lower band. One paisa more crosses.
        $onBoundary = $calculator->compute(TransactionCostCalculator::SIDE_BUY, 1, 50_000);
        $justOver = $calculator->compute(TransactionCostCalculator::SIDE_BUY, 1, 50_000.01);

        $this->assertSame(0.36, $onBoundary->tierRatePercent);
        $this->assertSame(180.0, $onBoundary->commission);

        $this->assertSame(0.33, $justOver->tierRatePercent);
        $this->assertSame('NPR 50,001 to 500,000', $justOver->tierLabel);
        $this->assertSame(165.0, $justOver->commission);

        // The rate steps *down*, so crossing the boundary makes a bigger trade
        // cheaper in commission. That is the schedule, not a bug.
        $this->assertLessThan($onBoundary->commission, $justOver->commission);
    }

    #[Test]
    public function the_rupee_floor_takes_over_on_a_tiny_trade(): void
    {
        // 1 x NPR 100 = 100 turnover; 0.36% of that is 36 paisa, under the
        // NPR 10 minimum.
        $cost = $this->calculator()->compute(TransactionCostCalculator::SIDE_BUY, 1, 100);

        $this->assertSame(10.0, $cost->commission);
        $this->assertTrue($cost->minimumCommissionApplied);
        // The DP charge dwarfs everything else at this size, which is exactly
        // why a flat fee matters more than a rate on a small trade.
        $this->assertSame(25.0, $cost->dpCharge);
        $this->assertSame(35.02, $cost->total);
        $this->assertSame(35.02, $cost->costPercent());
    }

    #[Test]
    public function progressive_application_charges_each_slice_at_its_own_rate(): void
    {
        $schedule = TransactionCostSchedule::fromArray([
            'instrument_class' => TransactionCostSchedule::CLASS_EQUITY,
            'application' => TransactionCostSchedule::APPLICATION_PROGRESSIVE,
            'minimum_commission' => 10.0,
            'sebon_fee_percent' => 0.015,
            'dp_charge' => 25.0,
            'tiers' => [
                ['up_to' => 50_000, 'rate_percent' => 0.36],
                ['up_to' => 500_000, 'rate_percent' => 0.33],
                ['up_to' => null, 'rate_percent' => 0.31],
            ],
        ]);

        $cost = (new TransactionCostCalculator([$schedule]))
            ->compute(TransactionCostCalculator::SIDE_BUY, 1, 100_000);

        // 50,000 @ 0.36% = 180.00, then 50,000 @ 0.33% = 165.00.
        $this->assertSame(345.0, $cost->commission);
        $this->assertSame(TransactionCostSchedule::APPLICATION_PROGRESSIVE, $cost->application);

        // Slab on the same turnover charges the whole amount at 0.33%.
        $slab = $this->calculator()->compute(TransactionCostCalculator::SIDE_BUY, 1, 100_000);
        $this->assertSame(330.0, $slab->commission);
    }

    // ------------------------------------------------------------------
    // Schedule resolution
    // ------------------------------------------------------------------

    #[Test]
    public function it_prices_a_trade_on_the_schedule_in_force_at_its_date(): void
    {
        $old = TransactionCostSchedule::fromArray([
            'instrument_class' => TransactionCostSchedule::CLASS_EQUITY,
            'effective_from' => '2020-01-01',
            'minimum_commission' => 0.0,
            'sebon_fee_percent' => 0.015,
            'dp_charge' => 25.0,
            'tiers' => [['up_to' => null, 'rate_percent' => 0.40]],
        ]);
        $new = TransactionCostSchedule::fromArray([
            'instrument_class' => TransactionCostSchedule::CLASS_EQUITY,
            'effective_from' => '2025-01-01',
            'minimum_commission' => 0.0,
            'sebon_fee_percent' => 0.015,
            'dp_charge' => 25.0,
            'tiers' => [['up_to' => null, 'rate_percent' => 0.30]],
        ]);

        $calculator = new TransactionCostCalculator([$new, $old]);

        $before = $calculator->compute(
            TransactionCostCalculator::SIDE_BUY, 1, 100_000, Carbon::parse('2024-06-01'),
        );
        $after = $calculator->compute(
            TransactionCostCalculator::SIDE_BUY, 1, 100_000, Carbon::parse('2026-06-01'),
        );

        $this->assertSame(0.40, $before->tierRatePercent);
        $this->assertSame('2020-01-01', $before->scheduleEffectiveFrom);
        $this->assertSame(0.30, $after->tierRatePercent);
        $this->assertSame('2025-01-01', $after->scheduleEffectiveFrom);
    }

    #[Test]
    public function an_unmapped_instrument_class_falls_back_and_says_so(): void
    {
        $cost = $this->calculator()->compute(
            TransactionCostCalculator::SIDE_BUY, 10, 1000, null, 'promoter',
        );

        $this->assertSame(TransactionCostSchedule::CLASS_EQUITY, $cost->instrumentClass);
        $this->assertNotNull($cost->note);
        $this->assertStringContainsString('promoter', (string) $cost->note);
    }

    #[Test]
    public function a_mutual_fund_is_priced_on_its_own_flat_schedule(): void
    {
        $cost = $this->calculator()->compute(
            TransactionCostCalculator::SIDE_BUY,
            1000,
            10.05,
            null,
            TransactionCostSchedule::CLASS_MUTUAL_FUND,
        );

        $this->assertSame(TransactionCostSchedule::CLASS_MUTUAL_FUND, $cost->instrumentClass);
        $this->assertSame(0.15, $cost->tierRatePercent);
        $this->assertNull($cost->note);
    }

    #[Test]
    public function built_in_rates_are_labelled_as_assumed_not_configured(): void
    {
        $cost = $this->calculator()->compute(TransactionCostCalculator::SIDE_BUY, 10, 1000);

        $this->assertSame(TransactionCostSchedule::SOURCE_BUILT_IN, $cost->scheduleSource);
    }

    #[Test]
    public function a_zero_turnover_trade_is_not_a_free_trade(): void
    {
        $cost = $this->calculator()->compute(TransactionCostCalculator::SIDE_BUY, 0, 1000);

        $this->assertSame(0.0, $cost->total);
        $this->assertSame(0.0, $cost->dpCharge);
        $this->assertFalse($cost->minimumCommissionApplied);
        // Null rather than 0.0: there is no trade to express a cost ratio of.
        $this->assertNull($cost->costPercent());
        $this->assertNull($cost->tierLabel);
    }

    // ------------------------------------------------------------------
    // Round trips, and the result they can invert
    // ------------------------------------------------------------------

    #[Test]
    public function a_round_trip_costs_between_about_half_and_one_and_a_half_percent(): void
    {
        $calculator = $this->calculator();

        // A mid-sized round trip: the band the README quotes.
        $mid = $calculator->roundTrip(100, 500.0, 520.0);
        $this->assertGreaterThan(0.6, $mid['round_trip_percent']);
        $this->assertLessThan(1.2, $mid['round_trip_percent']);

        // A large one is cheaper in percentage terms, because the rate steps
        // down and the DP charge stops mattering.
        $large = $calculator->roundTrip(10_000, 1_000.0, 1_010.0);
        $this->assertLessThan($mid['round_trip_percent'], $large['round_trip_percent']);
    }

    #[Test]
    public function costs_flip_a_marginal_gain_into_a_loss(): void
    {
        $calculator = $this->calculator();

        // 100 shares bought at 500 and sold at 502: NPR 200 gross, 0.4%.
        $quantity = 100.0;
        $buyPrice = 500.0;
        $sellPrice = 502.0;

        $grossGain = ($sellPrice - $buyPrice) * $quantity;
        $this->assertSame(200.0, $grossGain);

        $trip = $calculator->roundTrip($quantity, $buyPrice, $sellPrice);
        $netGain = round($grossGain - $trip['total_cost'], 2);

        // The costs are larger than the gain, so a trade that looked profitable
        // gross is a loss net. This is the case the whole calculator exists for.
        $this->assertGreaterThan($grossGain, $trip['total_cost']);
        $this->assertLessThan(0.0, $netGain);

        // And the same conclusion reached through the ledger's own arithmetic:
        // buy fees capitalise into the cost base, sell fees come off proceeds,
        // exactly as HoldingCalculator and closedTrades() do it.
        $trade = new ClosedTrade(
            symbol: 'NTC',
            openedOn: Carbon::parse('2026-01-05'),
            closedOn: Carbon::parse('2026-02-05'),
            quantity: $quantity,
            costBasis: round($quantity * $buyPrice + $trip['buy']->total, 2),
            proceeds: round($quantity * $sellPrice - $trip['sell']->total, 2),
            fees: $trip['total_cost'],
            gain: $netGain,
        );

        $this->assertFalse($trade->isWin());
        $this->assertSame($netGain, round($trade->proceeds - $trade->costBasis, 2));
    }

    #[Test]
    public function the_effective_price_per_unit_is_the_break_even_price(): void
    {
        $buy = $this->calculator()->compute(TransactionCostCalculator::SIDE_BUY, 100, 500);

        // Costs push the real entry above the quoted price.
        $this->assertGreaterThan(500.0, (float) $buy->effectivePricePerUnit());
        $this->assertSame(round((50_000 + $buy->total) / 100, 4), $buy->effectivePricePerUnit());
        $this->assertSame(round(-(50_000 + $buy->total), 2), $buy->netCashFlow());
    }

    #[Test]
    public function a_sell_returns_less_cash_than_its_turnover(): void
    {
        $sell = $this->calculator()->compute(TransactionCostCalculator::SIDE_SELL, 100, 500);

        $this->assertSame(TransactionCostCalculator::SIDE_SELL, $sell->side);
        $this->assertSame(25.0, $sell->dpCharge);
        $this->assertSame(round(50_000 - $sell->total, 2), $sell->netCashFlow());
    }

    // ------------------------------------------------------------------
    // Reconciliation — read-only, never a rewrite
    // ------------------------------------------------------------------

    #[Test]
    public function it_reports_the_variance_against_a_recorded_fee_without_changing_it(): void
    {
        $result = $this->calculator()->reconcile(
            TransactionCostCalculator::SIDE_BUY, 100, 500.0, recordedFees: 250.0,
        );

        $computed = $this->calculator()->compute(TransactionCostCalculator::SIDE_BUY, 100, 500.0);

        $this->assertSame(250.0, $result['recorded_fees']);
        $this->assertSame($computed->total, $result['computed']['total']);
        $this->assertSame(round($computed->total - 250.0, 2), $result['variance']);
        $this->assertFalse($result['recorded_is_zero']);
        $this->assertFalse($result['matches']);
    }

    #[Test]
    public function a_recorded_zero_fee_is_flagged_rather_than_treated_as_a_match(): void
    {
        $result = $this->calculator()->reconcile(
            TransactionCostCalculator::SIDE_BUY, 100, 500.0, recordedFees: 0.0,
        );

        $this->assertTrue($result['recorded_is_zero']);
        $this->assertFalse($result['matches']);
        $this->assertGreaterThan(0.0, $result['variance']);
    }
}
