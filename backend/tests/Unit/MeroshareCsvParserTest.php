<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Import\MeroshareCsvParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MeroshareCsvParserTest extends TestCase
{
    private MeroshareCsvParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new MeroshareCsvParser;
    }

    private function holdingsCsv(): string
    {
        return <<<'CSV'
        "S.N","Scrip","Current Balance","Last Closing Price","Value as of Last Closing Price","Last Transaction Price (LTP)","Value as of LTP"
        "1","ALICL","10.0","210.00","2100.00","210.00","2100.00"
        "2","NABIL","20.0","310.00","6200.00","310.00","6200.00"
        "3","NIBLSF","100.0","10.50","1050.00","10.50","1050.00"
        "Total :"," "," "," ","9350.00"," ","9350.00"
        CSV;
    }

    private function waccCsv(): string
    {
        return <<<'CSV'
        "S.N","Demat","Scrip Name","WACC Calculated Quantity","WACC Rate","Total Cost of Capital","Last Modification Date"
        "1","9999000000001234","ALICL","10","200.25","2002.50","2001-01-15 12:00:00"
        "2","9999000000001234","NABIL","20","300.50","6010.00","2001-02-15 12:00:00"
        "3","9999000000001234","NIBLSF","100","10.00","1000.00","2001-03-15 12:00:00"
        CSV;
    }

    public function test_detects_each_export_type_from_its_header(): void
    {
        $this->assertSame('holdings', $this->parser->detectType($this->holdingsCsv()));
        $this->assertSame('wacc', $this->parser->detectType($this->waccCsv()));
    }

    public function test_rejects_a_file_that_is_not_a_meroshare_export(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not look like a MeroShare export/');

        $this->parser->detectType("\"Name\",\"Age\"\n\"Bob\",\"30\"");
    }

    public function test_parses_holdings_and_drops_the_total_footer(): void
    {
        $rows = $this->parser->parseHoldings($this->holdingsCsv());

        // The "Total :" row must not become a holding.
        $this->assertCount(3, $rows);
        $this->assertSame(['ALICL', 'NABIL', 'NIBLSF'], array_column($rows, 'symbol'));

        $this->assertSame(10.0, $rows[0]['quantity']);
        $this->assertSame(210.00, $rows[0]['last_price']);
        $this->assertSame(2100.00, $rows[0]['value']);
    }

    public function test_parses_wacc_rows_and_extracts_the_demat(): void
    {
        $parsed = $this->parser->parseWacc($this->waccCsv());

        $this->assertCount(3, $parsed['rows']);
        $this->assertSame('9999000000001234', $parsed['demat']);

        $this->assertSame('ALICL', $parsed['rows'][0]['symbol']);
        $this->assertSame(10.0, $parsed['rows'][0]['quantity']);
        $this->assertSame(200.25, $parsed['rows'][0]['rate']);
        $this->assertSame('2001-01-15', $parsed['rows'][0]['modified_at']);
    }

    public function test_demat_is_reduced_to_its_last_four_digits(): void
    {
        // Storing a full demat account number is a liability with no upside once
        // the merge is done.
        $this->assertSame('****1234', $this->parser->maskDemat('9999000000001234'));
        $this->assertNull($this->parser->maskDemat('12'));
        $this->assertNull($this->parser->maskDemat(null));
    }

    public function test_columns_are_located_by_name_so_reordering_is_survivable(): void
    {
        $reordered = <<<'CSV'
        "Scrip","Last Transaction Price (LTP)","S.N","Current Balance","Value as of LTP"
        "NTC","500.00","1","10.0","5000.00"
        CSV;

        $rows = $this->parser->parseHoldings($reordered);

        $this->assertSame('NTC', $rows[0]['symbol']);
        $this->assertSame(10.0, $rows[0]['quantity']);
        $this->assertSame(500.00, $rows[0]['last_price']);
    }

    public function test_missing_required_column_names_the_column(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Current Balance/');

        $this->parser->parseHoldings("\"S.N\",\"Scrip\"\n\"1\",\"NABIL\"");
    }

    public function test_zero_balance_rows_are_not_treated_as_holdings(): void
    {
        $csv = <<<'CSV'
        "S.N","Scrip","Current Balance","Value as of LTP"
        "1","NABIL","20.0","6200.00"
        "2","SOLD","0.0","0.00"
        CSV;

        $rows = $this->parser->parseHoldings($csv);

        $this->assertCount(1, $rows);
        $this->assertSame('NABIL', $rows[0]['symbol']);
    }

    public function test_a_utf8_bom_does_not_corrupt_the_first_header(): void
    {
        // Excel adds a BOM, which would otherwise become part of "S.N".
        $rows = $this->parser->parseHoldings("\xEF\xBB\xBF".$this->holdingsCsv());

        $this->assertCount(3, $rows);
    }

    public function test_windows_line_endings_are_handled(): void
    {
        $csv = str_replace("\n", "\r\n", $this->holdingsCsv());

        $this->assertCount(3, $this->parser->parseHoldings($csv));
    }
}
