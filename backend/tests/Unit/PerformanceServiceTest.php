<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PortfolioTransaction;
use App\Models\Stock;
use App\Services\Portfolio\PerformanceService;
use App\Services\Portfolio\PortfolioValuationService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Closed-trade reconstruction and XIRR only — no database.
 *
 * closedTrades() and xirr() take a collection of transactions rather than a
 * portfolio precisely so the arithmetic can be pinned down here, where the
 * ledger is a literal and every expected number can be worked out by hand.
 *
 * The app is booted (Tests\TestCase) only because Eloquent's attribute casts
 * need a connection resolver to exist — no query is ever run.
 */
final class PerformanceServiceTest extends TestCase
{
    private function service(): PerformanceService
    {
        return new PerformanceService(new PortfolioValuationService);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transaction(string $symbol, string $type, float $quantity, float $price, string $date, float $fees = 0.0): PortfolioTransaction
    {
        $transaction = new PortfolioTransaction([
            'type' => $type,
            'quantity' => $quantity,
            'price' => $price,
            'commission' => $fees,
            'other_fees' => 0,
            'transacted_on' => $date,
        ]);

        $transaction->stock_id = crc32($symbol);
        $transaction->setRelation('stock', new Stock(['symbol' => $symbol]));

        return $transaction;
    }

    #[Test]
    public function it_pairs_a_sell_back_to_the_buy_that_funded_it(): void
    {
        $trades = $this->service()->closedTrades(collect([
            $this->transaction('NTC', 'buy', 100, 800, '2025-01-10'),
            $this->transaction('NTC', 'sell', 100, 900, '2025-04-10'),
        ]));

        $this->assertCount(1, $trades);

        $trade = $trades[0];
        $this->assertSame('NTC', $trade->symbol);
        $this->assertSame(100.0, $trade->quantity);
        $this->assertSame(80000.0, $trade->costBasis);
        $this->assertSame(90000.0, $trade->proceeds);
        $this->assertSame(10000.0, $trade->gain);
        $this->assertSame(12.5, $trade->gainPercent());
        $this->assertSame(90, $trade->daysHeld());
        $this->assertTrue($trade->isWin());
    }

    #[Test]
    public function fees_are_capitalised_into_cost_and_deducted_from_proceeds(): void
    {
        $trades = $this->service()->closedTrades(collect([
            $this->transaction('SCB', 'buy', 10, 500, '2025-01-01', fees: 50),
            $this->transaction('SCB', 'sell', 10, 600, '2025-02-01', fees: 60),
        ]));

        // Cost base is 5,000 + 50 of fees; proceeds are 6,000 less 60 of fees.
        $this->assertSame(5050.0, $trades[0]->costBasis);
        $this->assertSame(5940.0, $trades[0]->proceeds);
        $this->assertSame(890.0, $trades[0]->gain);
    }

    #[Test]
    public function a_partial_sell_consumes_the_oldest_lot_first(): void
    {
        $trades = $this->service()->closedTrades(collect([
            $this->transaction('HIDCL', 'buy', 50, 200, '2024-01-01'),
            $this->transaction('HIDCL', 'buy', 50, 300, '2025-01-01'),
            // Selling 50 units must age against the 2024 lot, not the 2025 one,
            // even though the cost used is the blended average of both.
            $this->transaction('HIDCL', 'sell', 50, 400, '2025-07-01'),
        ]));

        $this->assertCount(1, $trades);
        $this->assertSame('2024-01-01', $trades[0]->openedOn->toDateString());
        // Average cost after both buys is 250, so 50 units cost 12,500.
        $this->assertSame(12500.0, $trades[0]->costBasis);
        $this->assertSame(20000.0, $trades[0]->proceeds);
        $this->assertSame(7500.0, $trades[0]->gain);
    }

    #[Test]
    public function a_sell_spanning_two_lots_uses_a_weighted_acquisition_date(): void
    {
        $trades = $this->service()->closedTrades(collect([
            $this->transaction('UPPER', 'buy', 100, 100, '2025-01-01'),
            $this->transaction('UPPER', 'buy', 100, 100, '2025-03-02'),
            $this->transaction('UPPER', 'sell', 200, 120, '2025-06-01'),
        ]));

        // Equal lots 60 days apart put the effective open at the midpoint.
        $this->assertSame('2025-01-31', $trades[0]->openedOn->toDateString());
        $this->assertSame(200.0, $trades[0]->quantity);
    }

    #[Test]
    public function bonus_units_inherit_the_positions_age_and_dilute_the_average(): void
    {
        $trades = $this->service()->closedTrades(collect([
            $this->transaction('NABIL', 'buy', 100, 500, '2024-06-01'),
            // A 1:1 bonus halves the average cost without costing anything.
            $this->transaction('NABIL', 'bonus', 100, 0, '2025-01-01'),
            $this->transaction('NABIL', 'sell', 200, 300, '2025-06-01'),
        ]));

        $this->assertCount(1, $trades);
        $this->assertSame(50000.0, $trades[0]->costBasis);
        $this->assertSame(60000.0, $trades[0]->proceeds);
        $this->assertSame(10000.0, $trades[0]->gain);
        // The bonus units are as old as the position, not as old as the award.
        $this->assertSame('2024-06-01', $trades[0]->openedOn->toDateString());
    }

    #[Test]
    public function dividends_are_income_and_never_counted_as_a_trade(): void
    {
        $trades = $this->service()->closedTrades(collect([
            $this->transaction('NTC', 'buy', 100, 800, '2025-01-01'),
            $this->transaction('NTC', 'dividend', 4500, 0, '2025-03-01'),
        ]));

        $this->assertSame([], $trades);
    }

    #[Test]
    public function selling_more_than_the_ledger_bought_does_not_invent_a_trade(): void
    {
        $trades = $this->service()->closedTrades(collect([
            $this->transaction('GHOST', 'sell', 100, 500, '2025-01-01'),
        ]));

        $this->assertSame([], $trades);
    }

    #[Test]
    public function a_loss_is_recorded_as_a_loss(): void
    {
        $trades = $this->service()->closedTrades(collect([
            $this->transaction('NFS', 'buy', 100, 1000, '2025-01-01'),
            $this->transaction('NFS', 'sell', 100, 600, '2025-05-01'),
        ]));

        $this->assertSame(-40000.0, $trades[0]->gain);
        $this->assertFalse($trades[0]->isWin());
        $this->assertSame(-40.0, $trades[0]->gainPercent());
    }

    #[Test]
    public function xirr_recovers_a_known_annual_rate(): void
    {
        // 100,000 in, worth 121,000 exactly two years later: 10% a year.
        $rate = $this->service()->xirr(
            collect([$this->transaction('X', 'buy', 1000, 100, Carbon::today()->subYears(2)->toDateString())]),
            121000,
        );

        $this->assertNotNull($rate);
        $this->assertEqualsWithDelta(10.0, $rate, 0.3);
    }

    #[Test]
    public function xirr_weights_later_contributions_less(): void
    {
        // The same total gain is a much better rate when most of the money only
        // arrived recently — which is the whole reason not to use gain/invested.
        $rate = $this->service()->xirr(
            collect([
                $this->transaction('X', 'buy', 100, 100, Carbon::today()->subYears(3)->toDateString()),
                $this->transaction('X', 'buy', 900, 100, Carbon::today()->subMonths(1)->toDateString()),
            ]),
            110000,
        );

        $this->assertNotNull($rate);
        $this->assertGreaterThan(10.0, $rate);
    }

    #[Test]
    public function the_deposit_benchmark_compounds_contributions_to_the_end_date(): void
    {
        // Rs 100,000 deposited two years ago at 10% is 121,000 today, so a
        // portfolio worth exactly that has drawn level with the deposit.
        $rate = $this->service()->rateOfReturn([
            ['date' => Carbon::today()->subYears(2), 'amount' => -100000.0],
            ['date' => Carbon::today(), 'amount' => 121000.0],
        ]);

        $this->assertNotNull($rate);
        $this->assertEqualsWithDelta(10.0, $rate, 0.3);
    }

    #[Test]
    public function rate_of_return_orders_flows_before_solving(): void
    {
        // Callers assemble flows from several sources, so the solver must not
        // depend on them arriving in date order.
        $ordered = $this->service()->rateOfReturn([
            ['date' => Carbon::today()->subYear(), 'amount' => -100000.0],
            ['date' => Carbon::today(), 'amount' => 110000.0],
        ]);
        $shuffled = $this->service()->rateOfReturn([
            ['date' => Carbon::today(), 'amount' => 110000.0],
            ['date' => Carbon::today()->subYear(), 'amount' => -100000.0],
        ]);

        $this->assertNotNull($ordered);
        $this->assertEqualsWithDelta((float) $ordered, (float) $shuffled, 0.01);
    }

    #[Test]
    public function rate_of_return_ignores_zero_flows(): void
    {
        // Bonus shares move no cash and must not be treated as a dated flow.
        $rate = $this->service()->rateOfReturn([
            ['date' => Carbon::today()->subYear(), 'amount' => -100000.0],
            ['date' => Carbon::today()->subMonths(6), 'amount' => 0.0],
            ['date' => Carbon::today(), 'amount' => 110000.0],
        ]);

        $this->assertNotNull($rate);
        $this->assertEqualsWithDelta(10.0, $rate, 0.3);
    }

    #[Test]
    public function xirr_is_null_without_flows_of_both_signs(): void
    {
        $this->assertNull($this->service()->xirr(
            collect([$this->transaction('X', 'buy', 100, 100, '2025-01-01')]),
            0,
        ));
    }

    #[Test]
    public function xirr_survives_a_total_loss_without_diverging(): void
    {
        $rate = $this->service()->xirr(
            collect([
                $this->transaction('X', 'buy', 100, 100, Carbon::today()->subYear()->toDateString()),
                $this->transaction('X', 'sell', 100, 1, Carbon::today()->toDateString()),
            ]),
            0,
        );

        $this->assertNotNull($rate);
        $this->assertLessThan(-90.0, $rate);
    }
}
