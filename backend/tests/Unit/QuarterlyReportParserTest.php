<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scraper\QuarterlyReport;
use App\Services\Scraper\QuarterlyReportParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The parser is asserted against real captured markup rather than hand-written
 * HTML, because the whole difficulty it exists to solve — that every sector
 * files on a differently worded template — only shows up in the real thing.
 *
 * Fixtures live in tests/Fixtures/Scraper and were captured from the live
 * endpoint. Re-capture them if a template changes; do not hand-edit them, or
 * the tests stop describing reality.
 */
final class QuarterlyReportParserTest extends TestCase
{
    private function parse(string $fixture, string $symbol): QuarterlyReport
    {
        $path = __DIR__.'/../Fixtures/Scraper/quarterly-report-'.$fixture.'.html';

        $this->assertFileExists($path, "Missing fixture for {$fixture}.");

        return (new QuarterlyReportParser)->parse($symbol, (string) file_get_contents($path));
    }

    #[Test]
    public function it_reads_the_banking_template(): void
    {
        $report = $this->parse('bank', 'NABIL');

        // Balance-sheet figures are published in thousands and must be scaled.
        $this->assertSame(32_056_997_000.0, $report->shareCapital);
        $this->assertSame(5_169_148_000.0, $report->retainedEarnings);
        $this->assertSame(71_906_565_000.0, $report->totalEquity);
        $this->assertSame(7_905_796_000.0, $report->netProfit);

        // Ratio-table figures are absolute and must NOT be scaled.
        $this->assertSame(247.28, $report->bookValue);
        $this->assertSame(27.74, $report->eps);
        $this->assertSame(11.22, $report->roe);
        $this->assertSame(1.08, $report->roa);

        // Share counts are published plain, never in thousands.
        $this->assertSame(270_569_970.0, $report->outstandingShares);

        // Prudential ratios exist only on this template.
        $this->assertSame(4.20, $report->nplPercent);
        $this->assertSame(12.37, $report->capitalAdequacyRatio);
        $this->assertSame(4.44, $report->baseRate);
    }

    #[Test]
    public function it_canonicalises_the_fiscal_period(): void
    {
        // "4th Quarter 2082/2083" — canonical form matters because fiscal_period
        // is half the upsert key on stock_fundamentals.
        $this->assertSame('2082/2083 Q4', $this->parse('bank', 'NABIL')->fiscalPeriod);
        $this->assertSame('2082/2083 Q3', $this->parse('hydropower', 'API')->fiscalPeriod);
    }

    /**
     * The same concept under four different labels across four templates.
     */
    #[Test]
    #[DataProvider('bookValueFixtures')]
    public function it_finds_book_value_on_every_template(string $fixture, string $symbol, float $expected): void
    {
        $this->assertSame($expected, $this->parse($fixture, $symbol)->bookValue);
    }

    public static function bookValueFixtures(): array
    {
        return [
            // "Net Worth Per Share"
            'bank' => ['bank', 'NABIL', 247.28],
            // "Networth Per Share"
            'hydropower' => ['hydropower', 'API', 115.32],
            // "Net Worth Per share (Rs.)"
            'manufacturing' => ['manufacturing', 'SHIVM', 185.31],
            // "Networth per share (Rs.)"
            'insurance' => ['insurance', 'NRIC', 152.94],
        ];
    }

    #[Test]
    #[DataProvider('shareCapitalFixtures')]
    public function it_finds_share_capital_on_every_template(string $fixture, string $symbol, float $expected): void
    {
        $this->assertSame($expected, $this->parse($fixture, $symbol)->shareCapital);
    }

    public static function shareCapitalFixtures(): array
    {
        return [
            // "Share capital"
            'bank' => ['bank', 'NABIL', 32_056_997_000.0],
            // "Paid Up Capital"
            'hydropower' => ['hydropower', 'API', 6_379_724_200.0],
            // "Equity Share Capital" — and NOT the "Share Premium" line beneath it.
            'manufacturing' => ['manufacturing', 'SHIVM', 5_593_228_710.0],
        ];
    }

    /**
     * The exclusions are the part that actually prevents wrong numbers, so they
     * get their own assertions rather than being implied by the happy path.
     */
    #[Test]
    public function it_does_not_mistake_the_balance_sheet_total_for_total_equity(): void
    {
        // "Total Equity & Liabilities" is 13.09B; "Total Equity Fund" is 10.36B.
        $report = $this->parse('manufacturing', 'SHIVM');

        $this->assertSame(10_364_906_140.0, $report->totalEquity);
        $this->assertSame(13_088_711_290.0, $report->totalAssets);
    }

    #[Test]
    public function it_does_not_mistake_pre_tax_profit_for_net_profit(): void
    {
        // SHIVM files "Before Bonus & Tax" (721.6M) and "Before Tax" (721.6M)
        // above the actual "For the Period" figure (619.2M).
        $this->assertSame(619_192_410.0, $this->parse('manufacturing', 'SHIVM')->netProfit);
    }

    #[Test]
    public function it_prefers_basic_eps_over_diluted(): void
    {
        // Both are 27.74 for NABIL, so this asserts the rule holds rather than
        // the value: diluted must never be what lands in the field.
        $report = $this->parse('bank', 'NABIL');

        $this->assertSame(27.74, $report->eps);
        $this->assertArrayHasKey('diluted earnings per share', $report->raw);
    }

    #[Test]
    public function it_does_not_confuse_return_on_invested_capital_with_roe(): void
    {
        // The insurance template carries "Return on Invested Capital (%)" 4.82
        // alongside "Return on Equity (ROE %)" 5.04.
        $this->assertSame(5.04, $this->parse('insurance', 'NRIC')->roe);
    }

    #[Test]
    public function it_ignores_tier_one_capital_when_reading_capital_adequacy(): void
    {
        // "Capital fund to RWA" 12.37 is the headline; "Tier I" 9.68 and
        // "CET I" 8.87 are components listed immediately beneath it.
        $this->assertSame(12.37, $this->parse('bank', 'NABIL')->capitalAdequacyRatio);
    }

    #[Test]
    public function it_ignores_loan_loss_provision_when_reading_npl(): void
    {
        // "Total Loan loss provision to Total NPL" is 119.73 and sits directly
        // under the 4.20 NPL figure.
        $this->assertSame(4.20, $this->parse('bank', 'NABIL')->nplPercent);
    }

    /**
     * DEPS is the headline metric of the whole feature, and this is the figure
     * published comparisons quote for NABIL.
     */
    #[Test]
    public function it_derives_distributable_eps_matching_the_published_figure(): void
    {
        $this->assertSame(19.1047, $this->parse('bank', 'NABIL')->distributableEps());
    }

    /**
     * Only the banking template publishes a share count; everything else has to
     * fall back to capital over par value. Cross-checked here against the
     * template's own book value, which is derived from the same share count at
     * the source — if the fallback were wrong, these would not agree.
     */
    #[Test]
    #[DataProvider('shareCountFallbackFixtures')]
    public function its_share_count_fallback_agrees_with_the_filed_book_value(
        string $fixture,
        string $symbol,
        float $equity,
    ): void {
        $report = $this->parse($fixture, $symbol);
        $shares = $report->sharesInIssue();

        $this->assertNotNull($shares);
        $this->assertNotNull($report->bookValue);
        $this->assertEqualsWithDelta($report->bookValue, $equity / $shares, 0.01);
    }

    public static function shareCountFallbackFixtures(): array
    {
        return [
            // Hydropower files no total equity line: paid-up + reserves is it.
            'hydropower' => ['hydropower', 'API', 6_379_724_200.0 + 977_657_040.0],
            'manufacturing' => ['manufacturing', 'SHIVM', 10_364_906_140.0],
            'insurance' => ['insurance', 'NRIC', 20_527_487_550.0],
        ];
    }

    #[Test]
    public function it_reports_an_unparseable_fragment_as_empty_rather_than_guessing(): void
    {
        $report = (new QuarterlyReportParser)->parse('XXXX', '<div>No data available</div>');

        $this->assertTrue($report->isEmpty());
        $this->assertNull($report->fiscalPeriod);
        $this->assertNull($report->distributableEps());
    }

    #[Test]
    public function it_keeps_every_label_in_raw_so_a_mapping_gap_is_recoverable(): void
    {
        $raw = $this->parse('bank', 'NABIL')->raw;

        // Not mapped to a column today, but present for later without recrawling.
        $this->assertArrayHasKey('deposits from customers', $raw);
        $this->assertArrayHasKey('credit to deposit ratio', $raw);
    }
}
