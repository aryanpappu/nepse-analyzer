<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Playbook\Backtest\ScheduledTradeCostCalculator;
use App\Services\Playbook\Backtest\TradeCostSchedule;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The transaction-cost model.
 *
 * The roadmap puts the real NEPSE round trip at 0.6-1.2% of notional, which is
 * larger than a momentum plan's entire first target (+4% gross before the stop
 * risk is netted off). So the schedule is asserted rather than assumed: a
 * backtest whose cost model is quietly wrong by a factor of two would invert
 * every marginal grade.
 */
final class PlaybookBacktestCostTest extends TestCase
{
    private function calculator(?TradeCostSchedule $schedule = null): ScheduledTradeCostCalculator
    {
        return new ScheduledTradeCostCalculator($schedule ?? TradeCostSchedule::nepseEquityDefault());
    }

    #[Test]
    public function it_prices_a_round_trip_inside_the_published_band(): void
    {
        $calculator = $this->calculator();

        // Rs 100,000 each way: 0.33% commission + 0.015% SEBON + Rs 25 DP.
        $this->assertSame(370.0, $calculator->buyCost(100_000.0));
        $this->assertSame(370.0, $calculator->sellCost(100_000.0));

        $roundTrip = ($calculator->buyCost(100_000.0) + $calculator->sellCost(100_000.0)) / 100_000.0 * 100;

        $this->assertGreaterThanOrEqual(0.6, $roundTrip);
        $this->assertLessThanOrEqual(1.2, $roundTrip);
    }

    #[Test]
    public function it_walks_the_commission_tiers(): void
    {
        $schedule = TradeCostSchedule::nepseEquityDefault();

        $this->assertSame(0.0036, $schedule->commissionRateFor(50_000.0));
        $this->assertSame(0.0033, $schedule->commissionRateFor(50_001.0));
        $this->assertSame(0.0033, $schedule->commissionRateFor(500_000.0));
        $this->assertSame(0.0031, $schedule->commissionRateFor(500_001.0));
        $this->assertSame(0.0027, $schedule->commissionRateFor(2_000_001.0));
        $this->assertSame(0.0024, $schedule->commissionRateFor(50_000_000.0));
    }

    #[Test]
    public function a_tiny_ticket_pays_the_minimum_commission_not_a_fraction_of_a_rupee(): void
    {
        // 0.36% of Rs 1,000 is Rs 3.60, below the Rs 10 floor. Small tickets
        // are where costs actually bite, so the floor must not be optional.
        $cost = $this->calculator()->buyCost(1_000.0);

        $this->assertSame(35.15, $cost);   // 10 floor + 0.15 SEBON + 25 DP
    }

    #[Test]
    public function the_free_schedule_charges_nothing_so_the_gross_leg_is_a_control(): void
    {
        $calculator = $this->calculator(TradeCostSchedule::free());

        $this->assertSame(0.0, $calculator->buyCost(1_000_000.0));
        $this->assertSame(0.0, $calculator->sellCost(1_000_000.0));
    }

    #[Test]
    public function a_zero_or_negative_turnover_costs_nothing(): void
    {
        $this->assertSame(0.0, $this->calculator()->buyCost(0.0));
        $this->assertSame(0.0, $this->calculator()->sellCost(-5.0));
    }

    #[Test]
    public function config_overrides_the_schedule_field_by_field(): void
    {
        // The point of driving this from config: when the real fee schedule
        // lands, one array changes and nothing in the simulator moves.
        $schedule = TradeCostSchedule::fromConfig([
            'commission_tiers' => [
                ['up_to' => 100_000.0, 'rate' => 0.004],
                ['up_to' => null, 'rate' => 0.002],
            ],
            'minimum_commission' => 20.0,
            'sebon_fee_rate' => 0.0002,
            'dp_charge_per_side' => 30.0,
            'dp_charge_on_buy' => false,
        ]);

        $this->assertSame(0.004, $schedule->commissionRateFor(100_000.0));
        $this->assertSame(0.002, $schedule->commissionRateFor(100_001.0));
        $this->assertSame(20.0, $schedule->minimumCommission);
        $this->assertFalse($schedule->dpChargeOnBuy);
        // Not overridden, so it keeps the default.
        $this->assertTrue($schedule->dpChargeOnSell);

        $calculator = $this->calculator($schedule);

        $this->assertSame(420.0, $calculator->buyCost(100_000.0));    // 400 + 20 SEBON, no DP
        $this->assertSame(450.0, $calculator->sellCost(100_000.0));   // 400 + 20 SEBON + 30 DP
    }

    #[Test]
    public function an_empty_config_falls_back_to_the_default_rather_than_charging_nothing(): void
    {
        // A missing config key must not silently produce a free-trading
        // backtest — that is the failure mode this test exists to prevent.
        $schedule = TradeCostSchedule::fromConfig([]);

        $this->assertSame(
            TradeCostSchedule::nepseEquityDefault()->commissionTiers,
            $schedule->commissionTiers,
        );
        $this->assertGreaterThan(0.0, $this->calculator($schedule)->buyCost(100_000.0));
    }

    #[Test]
    public function it_describes_the_schedule_it_applied(): void
    {
        $described = $this->calculator()->describe();

        $this->assertSame(0.015, $described['sebon_fee_percent']);
        $this->assertSame(25.0, $described['dp_charge_per_side']);
        $this->assertCount(5, $described['commission_tiers']);
    }
}
