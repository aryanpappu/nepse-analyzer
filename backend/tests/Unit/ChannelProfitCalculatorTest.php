<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\AcquisitionChannel;
use App\Services\Portfolio\ChannelProfitCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChannelProfitCalculatorTest extends TestCase
{
    private ChannelProfitCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new ChannelProfitCalculator;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|null
     */
    private function bucket(array $result, AcquisitionChannel $channel): ?array
    {
        foreach ($result['channels'] as $bucket) {
            if ($bucket['channel'] === $channel->value) {
                return $bucket;
            }
        }

        return null;
    }

    #[Test]
    public function bucket_gains_sum_to_the_total_gain(): void
    {
        // The invariant. A split that does not reconcile is hiding a parcel.
        $result = $this->calculator->split([
            ['channel' => AcquisitionChannel::Ipo, 'symbol' => 'NIFRA', 'quantity' => 54, 'cost_basis' => 5400.0, 'market_value' => 21600.0],
            ['channel' => AcquisitionChannel::Secondary, 'symbol' => 'NTC', 'quantity' => 520, 'cost_basis' => 475297.21, 'market_value' => 442000.0],
            ['channel' => AcquisitionChannel::Bonus, 'symbol' => 'NTC', 'quantity' => 20, 'cost_basis' => 0.0, 'market_value' => 17000.0],
        ]);

        $this->assertTrue($result['reconciles']);
        $this->assertSame(0.0, $result['reconciliation_error']);

        $summed = array_sum(array_column($result['channels'], 'gain'));
        $this->assertSame(round($summed, 2), $result['total']['gain']);
    }

    #[Test]
    public function an_ipo_windfall_does_not_flatter_the_trading_result(): void
    {
        // The whole point: a 4x IPO alongside a losing book must not be able to
        // report the trading as profitable.
        $result = $this->calculator->split([
            ['channel' => AcquisitionChannel::Ipo, 'symbol' => 'NIFRA', 'quantity' => 100, 'cost_basis' => 10000.0, 'market_value' => 40000.0],
            ['channel' => AcquisitionChannel::Secondary, 'symbol' => 'NFS', 'quantity' => 100, 'cost_basis' => 100000.0, 'market_value' => 80000.0],
        ]);

        // Blended, this book is up 10,000.
        $this->assertSame(10000.0, $result['total']['gain']);

        // Split, the trading is down 20,000 — which is the honest answer.
        $this->assertSame(-20000.0, $result['market_priced']['gain']);
        $this->assertSame(-20.0, $result['market_priced']['return_percent']);

        $this->assertSame(30000.0, $this->bucket($result, AcquisitionChannel::Ipo)['gain']);
    }

    #[Test]
    public function a_parcel_with_no_price_is_excluded_and_named_not_valued_at_cost(): void
    {
        // NICADF has a holding but no series price. Marking it at cost would
        // report a silent 0% and pull the bucket's return toward zero.
        $result = $this->calculator->split([
            ['channel' => AcquisitionChannel::Secondary, 'symbol' => 'NTC', 'quantity' => 10, 'cost_basis' => 9000.0, 'market_value' => 10000.0],
            ['channel' => AcquisitionChannel::Ipo, 'symbol' => 'NICADF', 'quantity' => 9059, 'cost_basis' => 93126.52, 'market_value' => null],
        ]);

        $this->assertFalse($result['coverage']['complete']);
        $this->assertSame(1, $result['coverage']['parcels_excluded']);
        $this->assertStringContainsString('NICADF', (string) $result['coverage']['note']);

        // Its cost is not counted either — half a parcel is worse than none.
        $this->assertSame(9000.0, $result['total']['cost_basis']);
        $this->assertSame(['NICADF'], $this->bucket($result, AcquisitionChannel::Ipo)['excluded_symbols']);
    }

    #[Test]
    public function an_empty_bucket_returns_null_not_zero_percent(): void
    {
        $result = $this->calculator->split([
            ['channel' => AcquisitionChannel::Ipo, 'symbol' => 'NIFRA', 'quantity' => 54, 'cost_basis' => 5400.0, 'market_value' => 21600.0],
        ]);

        // No secondary parcels at all — the honest answer is "no data", not 0%.
        $this->assertNull($result['market_priced']['return_percent']);
        $this->assertNull($result['market_priced']['gain']);
    }

    #[Test]
    public function a_zero_cost_bonus_parcel_reports_no_return_percentage(): void
    {
        // Bonus units cost nothing, so a percentage return on them is a
        // division by zero dressed up as insight.
        $result = $this->calculator->split([
            ['channel' => AcquisitionChannel::Bonus, 'symbol' => 'NTC', 'quantity' => 20, 'cost_basis' => 0.0, 'market_value' => 17000.0],
        ]);

        $bonus = $this->bucket($result, AcquisitionChannel::Bonus);
        $this->assertSame(17000.0, $bonus['gain']);
        $this->assertNull($bonus['return_percent']);
    }

    #[Test]
    public function an_unrecorded_channel_is_reported_separately_not_folded_in(): void
    {
        $result = $this->calculator->split([
            ['channel' => AcquisitionChannel::Secondary, 'symbol' => 'NTC', 'quantity' => 10, 'cost_basis' => 9000.0, 'market_value' => 10000.0],
            ['channel' => AcquisitionChannel::Unknown, 'symbol' => 'ILI', 'quantity' => 12, 'cost_basis' => 2569.10, 'market_value' => 3000.0],
        ]);

        $unknown = $this->bucket($result, AcquisitionChannel::Unknown);
        $this->assertNotNull($unknown);
        $this->assertSame(2569.10, $unknown['cost_basis']);

        // It must not have leaked into the market-priced figure.
        $this->assertSame(9000.0, $result['market_priced']['cost_basis']);
    }

    #[Test]
    public function shares_of_cost_sum_to_one_hundred_percent(): void
    {
        $result = $this->calculator->split([
            ['channel' => AcquisitionChannel::Secondary, 'symbol' => 'NTC', 'quantity' => 10, 'cost_basis' => 75000.0, 'market_value' => 80000.0],
            ['channel' => AcquisitionChannel::Ipo, 'symbol' => 'NIFRA', 'quantity' => 54, 'cost_basis' => 25000.0, 'market_value' => 40000.0],
        ]);

        $shares = array_column($result['channels'], 'share_of_cost_percent');
        $this->assertSame(100.0, round(array_sum(array_filter($shares)), 2));
    }

    #[Test]
    public function an_empty_portfolio_reports_nulls_rather_than_zeroes(): void
    {
        $result = $this->calculator->split([]);

        $this->assertSame([], $result['channels']);
        $this->assertNull($result['total']['cost_basis']);
        $this->assertNull($result['total']['gain']);
        $this->assertNull($result['total']['return_percent']);
        $this->assertTrue($result['reconciles']);
    }

    #[Test]
    public function a_channel_given_as_a_string_is_parsed(): void
    {
        $result = $this->calculator->split([
            ['channel' => 'ipo', 'symbol' => 'NIFRA', 'quantity' => 54, 'cost_basis' => 5400.0, 'market_value' => 21600.0],
        ]);

        $this->assertSame(16200.0, $this->bucket($result, AcquisitionChannel::Ipo)['gain']);
    }

    #[Test]
    public function an_unrecognised_channel_string_lands_in_unknown_not_in_trading(): void
    {
        $result = $this->calculator->split([
            ['channel' => 'margin_purchase', 'symbol' => 'XYZ', 'quantity' => 1, 'cost_basis' => 100.0, 'market_value' => 150.0],
        ]);

        $this->assertSame(50.0, $this->bucket($result, AcquisitionChannel::Unknown)['gain']);
        $this->assertNull($result['market_priced']['gain']);
    }
}
