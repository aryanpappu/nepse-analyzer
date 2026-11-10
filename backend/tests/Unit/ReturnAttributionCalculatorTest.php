<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Portfolio\ReturnAttributionCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Return attribution as pure arithmetic over plain arrays — no database, no
 * app boot, same shape as IndexReturnCalculatorTest.
 *
 * The load-bearing test here is the reconciliation invariant: per-holding
 * contributions must sum to the portfolio's return over the window, exactly.
 * An attribution that does not add up cannot answer "is the shortfall two bad
 * scrips or the whole book", which is the only reason it exists.
 */
final class ReturnAttributionCalculatorTest extends TestCase
{
    private function calculator(): ReturnAttributionCalculator
    {
        return new ReturnAttributionCalculator;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function position(string $symbol, array $overrides = []): array
    {
        return $overrides + [
            'symbol' => $symbol,
            'sector' => 'Commercial Bank',
            'sector_slug' => 'commercial-bank',
            'opening_value' => 0.0,
            'closing_value' => 0.0,
            'net_contribution' => 0.0,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function sumOfContributions(array $result): float
    {
        return array_sum(array_map(
            static fn (array $row): float => (float) ($row['contribution_percent'] ?? 0.0),
            $result['holdings'],
        ));
    }

    // ------------------------------------------------------------------
    // The invariant
    // ------------------------------------------------------------------

    #[Test]
    public function contributions_sum_to_the_portfolio_return(): void
    {
        // Two scrips bought inside the window, one up 20%, one down 10%.
        $result = $this->calculator()->contributions([
            $this->position('NABIL', ['net_contribution' => 100000.0, 'closing_value' => 120000.0]),
            $this->position('NICA', ['net_contribution' => 100000.0, 'closing_value' => 90000.0]),
        ]);

        // Base 200,000, profit +20,000 - 10,000 = +10,000 => +5%.
        self::assertSame(5.0, $result['total']['return_percent']);
        self::assertSame(200000.0, $result['total']['capital_base']);
        self::assertSame(10000.0, $result['total']['profit']);

        self::assertTrue($result['reconciles']);
        self::assertSame(5.0, round($this->sumOfContributions($result), 4));
    }

    #[Test]
    public function each_contribution_is_its_weight_times_its_own_return(): void
    {
        $result = $this->calculator()->contributions([
            $this->position('NABIL', ['net_contribution' => 150000.0, 'closing_value' => 180000.0]),
            $this->position('NICA', ['net_contribution' => 50000.0, 'closing_value' => 45000.0]),
        ]);

        foreach ($result['holdings'] as $row) {
            self::assertSame(
                round((float) $row['weight_percent'] / 100 * (float) $row['return_percent'], 4),
                round((float) $row['contribution_percent'], 4),
                $row['symbol'].' contribution must equal weight x return',
            );
        }

        // 75% weight at +20% and 25% at -10% => 15% - 2.5% = 12.5%.
        self::assertSame(12.5, $result['total']['return_percent']);
        self::assertSame(12.5, round($this->sumOfContributions($result), 4));
    }

    #[Test]
    public function the_invariant_survives_mid_window_additions_sales_and_dividends(): void
    {
        $result = $this->calculator()->contributions([
            // Held from the start, added to mid-window.
            $this->position('NABIL', [
                'opening_value' => 200000.0,
                'net_contribution' => 50000.0,
                'closing_value' => 280000.0,
            ]),
            // Held from the start, partly sold (a sale is a negative contribution).
            $this->position('NICA', [
                'opening_value' => 100000.0,
                'net_contribution' => -40000.0,
                'closing_value' => 55000.0,
            ]),
            // Bought and fully sold inside the window: closing value is a real zero.
            $this->position('HIDCL', [
                'net_contribution' => 30000.0,
                'closing_value' => 0.0,
            ]),
            // Dividend received on a position held from the start.
            $this->position('UPPER', [
                'opening_value' => 60000.0,
                'net_contribution' => -3000.0,
                'closing_value' => 61000.0,
            ]),
        ]);

        self::assertTrue($result['reconciles']);
        self::assertSame(
            (float) $result['total']['return_percent'],
            round($this->sumOfContributions($result), 4),
        );
        self::assertSame(0.0, $result['reconciliation_error_percent']);
    }

    #[Test]
    public function a_position_with_no_capital_base_still_contributes_but_reports_no_return_of_its_own(): void
    {
        // A dividend-only row: nothing at the open, no money put in, cash out.
        // Its profit is real, so it must stay in the sum; its own return and
        // weight are undefined and reported as null rather than fabricated.
        $result = $this->calculator()->contributions([
            $this->position('NABIL', ['net_contribution' => 100000.0, 'closing_value' => 110000.0]),
            $this->position('NICA', ['net_contribution' => -500.0, 'closing_value' => 0.0]),
        ]);

        $rows = array_column($result['holdings'], null, 'symbol');

        self::assertNull($rows['NICA']['return_percent']);
        self::assertNull($rows['NICA']['weight_percent']);
        self::assertNotNull($rows['NICA']['contribution_percent']);

        self::assertTrue($result['reconciles']);
        self::assertSame(
            (float) $result['total']['return_percent'],
            round($this->sumOfContributions($result), 4),
        );
    }

    // ------------------------------------------------------------------
    // Honest degradation
    // ------------------------------------------------------------------

    #[Test]
    public function a_holding_with_no_closing_price_is_excluded_and_named_not_counted_at_cost(): void
    {
        // This fictional fund has no price or NAV. Counting it at cost would
        // silently report 0% on it.
        $result = $this->calculator()->contributions([
            $this->position('NABIL', ['net_contribution' => 100000.0, 'closing_value' => 120000.0]),
            $this->position('NICADF', ['net_contribution' => 100000.0, 'closing_value' => null]),
        ]);

        self::assertCount(1, $result['holdings']);
        self::assertSame('NABIL', $result['holdings'][0]['symbol']);

        self::assertSame(1, $result['excluded_count']);
        self::assertSame('NICADF', $result['excluded'][0]['symbol']);
        self::assertStringContainsString('0% return', $result['excluded'][0]['reason']);

        // The reported return is the priced subset's, +20%, not a diluted 10%.
        self::assertSame(20.0, $result['total']['return_percent']);
        self::assertTrue($result['reconciles']);
    }

    #[Test]
    public function a_holding_with_no_opening_price_is_excluded_and_named(): void
    {
        $result = $this->calculator()->contributions([
            $this->position('NABIL', ['net_contribution' => 100000.0, 'closing_value' => 120000.0]),
            $this->position('MYSTERY', ['opening_value' => null, 'closing_value' => 50000.0]),
        ]);

        self::assertSame(1, $result['excluded_count']);
        self::assertSame('MYSTERY', $result['excluded'][0]['symbol']);
        self::assertStringContainsString('window start', $result['excluded'][0]['reason']);
    }

    #[Test]
    public function no_capital_at_risk_anywhere_reports_null_rather_than_zero_percent(): void
    {
        $result = $this->calculator()->contributions([
            $this->position('NABIL', ['opening_value' => 0.0, 'closing_value' => 0.0]),
        ]);

        self::assertNull($result['total']['return_percent']);
        self::assertNull($result['total']['capital_base']);
        self::assertNull($result['total']['profit']);
        self::assertNull($result['holdings'][0]['contribution_percent']);
    }

    #[Test]
    public function an_empty_book_reports_nothing_rather_than_a_flat_zero(): void
    {
        $result = $this->calculator()->contributions([]);

        self::assertSame([], $result['holdings']);
        self::assertNull($result['total']['return_percent']);
        self::assertTrue($result['reconciles']);
    }

    // ------------------------------------------------------------------
    // Sector selection
    // ------------------------------------------------------------------

    #[Test]
    public function a_sector_is_compared_against_its_sub_index_over_the_same_window(): void
    {
        $rows = $this->calculator()->sectorSelection([
            [
                'sector' => 'Commercial Bank',
                'sector_slug' => 'commercial-bank',
                'opening_value' => 0.0,
                'closing_value' => 120000.0,
                'net_contribution' => 100000.0,
                'index_slug' => 'banking-subindex',
                'index_name' => 'Banking SubIndex',
                'index_return_percent' => 12.0,
                'index_coverage_percent' => 100.0,
            ],
        ]);

        self::assertSame(20.0, $rows[0]['portfolio_return_percent']);
        self::assertSame(12.0, $rows[0]['index_return_percent']);
        self::assertSame(8.0, $rows[0]['selection_gap_percent']);
        self::assertTrue($rows[0]['beat_sub_index']);
    }

    #[Test]
    public function a_sector_that_lost_to_its_sub_index_reports_a_negative_gap(): void
    {
        $rows = $this->calculator()->sectorSelection([
            [
                'sector' => 'Hydro Power',
                'sector_slug' => 'hydropower',
                'opening_value' => 100000.0,
                'closing_value' => 95000.0,
                'net_contribution' => 0.0,
                'index_return_percent' => 8.0,
            ],
        ]);

        self::assertSame(-5.0, $rows[0]['portfolio_return_percent']);
        self::assertSame(-13.0, $rows[0]['selection_gap_percent']);
        self::assertFalse($rows[0]['beat_sub_index']);
    }

    #[Test]
    public function a_sector_with_no_sub_index_reports_that_rather_than_being_compared_against_the_wrong_thing(): void
    {
        // Debentures, government bonds, preference and promoter shares have
        // no sub-index at all.
        $rows = $this->calculator()->sectorSelection([
            [
                'sector' => 'Corporate Debenture',
                'sector_slug' => 'corporate-debenture',
                'opening_value' => 0.0,
                'closing_value' => 101000.0,
                'net_contribution' => 100000.0,
                'index_slug' => 'corporate-debenture',
                'index_return_percent' => null,
                'index_note' => 'No sub-index is published for Corporate Debenture.',
            ],
        ]);

        // The portfolio's own return is still real and still reported.
        self::assertSame(1.0, $rows[0]['portfolio_return_percent']);
        // The comparison is not.
        self::assertNull($rows[0]['index_return_percent']);
        self::assertNull($rows[0]['selection_gap_percent']);
        self::assertNull($rows[0]['beat_sub_index']);
        self::assertStringContainsString('No sub-index', (string) $rows[0]['index_note']);
    }

    #[Test]
    public function a_sector_with_no_capital_base_reports_a_null_return_not_zero(): void
    {
        $rows = $this->calculator()->sectorSelection([
            [
                'sector' => 'Unclassified',
                'sector_slug' => null,
                'opening_value' => 0.0,
                'closing_value' => 0.0,
                'net_contribution' => 0.0,
                'index_return_percent' => 9.0,
            ],
        ]);

        self::assertNull($rows[0]['portfolio_return_percent']);
        self::assertNull($rows[0]['selection_gap_percent']);
        self::assertNull($rows[0]['beat_sub_index']);
    }

    #[Test]
    public function sectors_are_ordered_by_capital_at_risk_largest_first(): void
    {
        $rows = $this->calculator()->sectorSelection([
            ['sector' => 'Small', 'sector_slug' => 's', 'opening_value' => 10000.0, 'closing_value' => 10000.0, 'net_contribution' => 0.0],
            ['sector' => 'Big', 'sector_slug' => 'b', 'opening_value' => 500000.0, 'closing_value' => 500000.0, 'net_contribution' => 0.0],
        ]);

        self::assertSame('Big', $rows[0]['sector']);
        self::assertSame('Small', $rows[1]['sector']);
    }
}
