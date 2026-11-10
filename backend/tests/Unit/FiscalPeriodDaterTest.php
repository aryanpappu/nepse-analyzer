<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scraper\FiscalPeriodDater;
use App\Services\Scraper\StockFundamentalPrecision;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Bikram Sambat fiscal period is the only thing standing between
 * `stock_fundamentals.outstanding_shares` and a dated benchmark weight, so the
 * conversion is pinned here rather than trusted.
 *
 * Every case below is a `fiscal_period` string that is *actually present* in
 * the database — three crawlers write the column and each spells the period
 * differently — plus the malformed shapes that must be refused rather than
 * guessed at.
 *
 * The app is booted (Tests\TestCase) only so config() resolves for the
 * quarter-end override; no query is ever run.
 */
final class FiscalPeriodDaterTest extends TestCase
{
    /** The last session the price table holds, in the tests that need one. */
    private function observed(string $date = '2026-09-07'): Carbon
    {
        return Carbon::parse($date)->startOfDay();
    }

    private function dater(): FiscalPeriodDater
    {
        return new FiscalPeriodDater;
    }

    // ------------------------------------------------------------------
    // Formats present in the database
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function realPeriods(): array
    {
        // [fiscal_period, expected reported_on, expected precision]
        return [
            // QuarterlyReportParser's canonical shape. Q4 ends when the fiscal
            // year does, which the conversion table holds outright.
            '2082/2083 Q4' => ['2082/2083 Q4', '2026-07-16', StockFundamentalPrecision::EXACT],
            '2081/2082 Q4' => ['2081/2082 Q4', '2025-07-16', StockFundamentalPrecision::EXACT],

            // Interim quarters end on BS month boundaries no formula gives, so
            // they are interpolated between two known fiscal-year ends.
            '2082/2083 Q3' => ['2082/2083 Q3', '2026-04-16', StockFundamentalPrecision::APPROXIMATE],
            '2082/2083 Q2' => ['2082/2083 Q2', '2026-01-15', StockFundamentalPrecision::APPROXIMATE],
            '2082/2083 Q1' => ['2082/2083 Q1', '2025-10-15', StockFundamentalPrecision::APPROXIMATE],

            // SharesansarSource writes the payout's fiscal year, with no quarter.
            '2081/2082' => ['2081/2082', '2025-07-16', StockFundamentalPrecision::EXACT],
            '2077/2078' => ['2077/2078', '2021-07-15', StockFundamentalPrecision::EXACT],
            '2078/2079' => ['2078/2079', '2022-07-16', StockFundamentalPrecision::EXACT],
            '2080/2081' => ['2080/2081', '2024-07-15', StockFundamentalPrecision::EXACT],

            // MerolaganiSource drops the leading century digit and uses a dash:
            // "28.36 (FY:082-083, Q:1)".
            '082-083 Q1' => ['082-083 Q1', '2025-10-15', StockFundamentalPrecision::APPROXIMATE],
            '082-083 Q3' => ['082-083 Q3', '2026-04-16', StockFundamentalPrecision::APPROXIMATE],
            '082-083 Q4' => ['082-083 Q4', '2026-07-16', StockFundamentalPrecision::EXACT],
        ];
    }

    #[Test]
    #[DataProvider('realPeriods')]
    public function it_dates_every_fiscal_period_format_in_the_database(
        string $period,
        string $expectedDate,
        string $expectedPrecision,
    ): void {
        $dating = $this->dater()->date($period, $this->observed());

        $this->assertTrue($dating->isDated(), "Expected {$period} to be datable, got: {$dating->rejection}");
        $this->assertSame($expectedDate, $dating->reportedOn());
        $this->assertSame($expectedPrecision, $dating->precision());
    }

    #[Test]
    public function the_three_digit_and_four_digit_spellings_of_one_quarter_agree(): void
    {
        // 082-083 Q3 (merolagani) and 2082/2083 Q3 (the filed report) are the
        // same quarter. If these ever disagreed, one scrip would hold two
        // differently dated copies of a single filing.
        $short = $this->dater()->date('082-083 Q3', $this->observed());
        $long = $this->dater()->date('2082/2083 Q3', $this->observed());

        $this->assertSame($long->reportedOn(), $short->reportedOn());
        $this->assertSame($long->canonicalLabel, $short->canonicalLabel);
        $this->assertSame('2082/2083 Q3', $short->canonicalLabel);
    }

    #[Test]
    public function a_two_digit_second_year_takes_its_century_from_the_first(): void
    {
        // QuarterlyReportParser's own regex accepts "2082/83" from a filing
        // header, so the dater has to as well.
        $dating = $this->dater()->date('2082/83 Q4', $this->observed());

        $this->assertTrue($dating->isDated());
        $this->assertSame('2082/2083 Q4', $dating->canonicalLabel);
        $this->assertSame('2026-07-16', $dating->reportedOn());
    }

    #[Test]
    public function an_uncanonicalised_filing_header_still_names_its_quarter(): void
    {
        $dating = $this->dater()->date('3rd Quarter 2082/2083', $this->observed());

        $this->assertSame(3, $dating->quarter);
        $this->assertSame('2082/2083 Q3', $dating->canonicalLabel);
    }

    // ------------------------------------------------------------------
    // Periods that must stay undated
    // ------------------------------------------------------------------

    #[Test]
    public function unknown_is_recorded_as_an_absence_not_a_parse_failure(): void
    {
        $dating = $this->dater()->date('unknown', $this->observed());

        $this->assertFalse($dating->isDated());
        $this->assertNull($dating->reportedOn());
        $this->assertNull($dating->precision());
        $this->assertStringContainsString('no reporting period', (string) $dating->rejection);
    }

    #[Test]
    public function a_period_that_has_not_ended_yet_is_left_undated(): void
    {
        // 48 rows in the live table claim FY 2083/2084 quarters that will not
        // end until 2027. A date there would sort ahead of every real filing,
        // so it is refused outright.
        $dating = $this->dater()->date('083-084 Q4', $this->observed());

        $this->assertFalse($dating->isDated());
        $this->assertStringContainsString('has not been filed yet', (string) $dating->rejection);
    }

    #[Test]
    public function the_future_check_uses_the_supplied_date_and_never_the_clock(): void
    {
        $period = '2082/2083 Q4';

        // Same period, two different observed sessions. Nothing about the
        // machine's clock may change the answer.
        $this->assertTrue($this->dater()->date($period, $this->observed('2026-09-07'))->isDated());
        $this->assertFalse($this->dater()->date($period, $this->observed('2026-01-01'))->isDated());

        // And with no reference at all, no future check is made rather than a
        // hidden one against today.
        $this->assertTrue($this->dater()->date($period)->isDated());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedPeriods(): array
    {
        return [
            'empty' => ['', 'empty'],
            'no year pair' => ['Q3', 'no fiscal-year pair'],
            'non consecutive years' => ['2082/2085', 'not a consecutive fiscal-year pair'],
            'descending years' => ['2083/2082', 'not a consecutive fiscal-year pair'],
            'wrong century' => ['1082/1083', 'outside the 20xx century'],
        ];
    }

    #[Test]
    #[DataProvider('malformedPeriods')]
    public function it_refuses_to_date_a_malformed_period(string $period, string $expectedReason): void
    {
        $dating = $this->dater()->date($period, $this->observed());

        $this->assertFalse($dating->isDated());
        $this->assertNull($dating->reportedOn());
        $this->assertStringContainsString($expectedReason, (string) $dating->rejection);
    }

    // ------------------------------------------------------------------
    // Precision
    // ------------------------------------------------------------------

    #[Test]
    public function a_fiscal_year_outside_the_conversion_table_is_dated_but_flagged(): void
    {
        // BS 2062 predates the table, so NepaliFiscalYear falls back and says
        // so. The row is still worth dating — it just must not claim precision.
        $dating = $this->dater()->date('2062/2063', $this->observed());

        $this->assertTrue($dating->isDated());
        $this->assertTrue($dating->approximate);
        $this->assertSame(StockFundamentalPrecision::APPROXIMATE, $dating->precision());
    }

    #[Test]
    public function an_asserted_quarter_end_overrides_the_interpolation_and_is_exact(): void
    {
        // The escape hatch for the interpolated quarters: a real published
        // boundary can be asserted in config without a code change, and is then
        // treated as fact rather than as an estimate.
        config(['nepse.fiscal_quarter_ends' => ['2082:3' => '2026-04-13']]);

        $dating = $this->dater()->date('2082/2083 Q3', $this->observed());

        $this->assertSame('2026-04-13', $dating->reportedOn());
        $this->assertFalse($dating->approximate);
        $this->assertSame(StockFundamentalPrecision::EXACT, $dating->precision());
    }

    #[Test]
    public function an_interpolated_quarter_end_lands_inside_its_own_fiscal_year(): void
    {
        // The interpolation makes no claim about BS month lengths, but it must
        // never produce a date outside the year it is dividing, and the
        // quarters must stay in order.
        $previous = null;

        foreach ([1, 2, 3, 4] as $quarter) {
            $dating = $this->dater()->date("2081/2082 Q{$quarter}", $this->observed());

            $this->assertTrue($dating->isDated());
            $date = (string) $dating->reportedOn();

            $this->assertGreaterThan('2024-07-16', $date, "Q{$quarter} fell before the fiscal year started");
            $this->assertLessThanOrEqual('2025-07-16', $date, "Q{$quarter} fell after the fiscal year ended");

            if ($previous !== null) {
                $this->assertGreaterThan($previous, $date, "Q{$quarter} did not follow the previous quarter");
            }

            $previous = $date;
        }
    }
}
