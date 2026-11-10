<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Import\BrokerStatementParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The parser is fed a cell grid, so it is testable without touching a zip, a
 * database or the filesystem. The rows below are fictional examples of the
 * broker export layout.
 */
final class BrokerStatementParserTest extends TestCase
{
    private BrokerStatementParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new BrokerStatementParser;
    }

    /**
     * @param  list<array<string, string>>  $dataRows
     * @return array<int, array<string, string>>
     */
    private function grid(array $dataRows): array
    {
        $grid = [
            14 => [
                'A' => 'SN', 'B' => 'Date AD', 'C' => 'Date BS', 'D' => 'Voucher No.',
                'E' => 'Particulars', 'F' => 'Reference No.', 'G' => 'Branch',
                'H' => 'Debit', 'I' => 'Credit', 'J' => 'Balance',
            ],
        ];

        $number = 15;

        foreach ($dataRows as $row) {
            $grid[$number++] = $row;
        }

        return $grid;
    }

    #[Test]
    public function it_classifies_each_row_kind_from_its_reference_prefix(): void
    {
        $result = $this->parser->parseGrid($this->grid([
            ['B' => '01/15/2001', 'E' => "Received from A CLIENT as 'On Accounts'", 'F' => 'R/000001/057-058', 'I' => '10,000.00', 'J' => '10,000.00 CR'],
            ['B' => '01/16/2001', 'E' => 'Receivable from client for purchase bill no. B/0000001/057-058 (TESTA 10 kitta @ 200.00)', 'F' => 'B/0000001/057-058', 'H' => '2,025.00', 'J' => '7,975.00 CR'],
            ['B' => '01/17/2001', 'E' => 'Payable to client for sell bill no. S/0000001/057-058 (TESTA 10 kitta @ 300.00)', 'F' => 'S/0000001/057-058', 'I' => '2,975.00', 'J' => '10,950.00 CR'],
            ['B' => '01/18/2001', 'E' => 'Payment made for bill no. S/0000001/057-058 of Mr/s A CLIENT', 'F' => 'DEMO-PAYMENT-001', 'H' => '2,975.00', 'J' => '7,975.00 CR'],
        ]));

        $kinds = array_column($result['rows'], 'kind');

        $this->assertSame([
            BrokerStatementParser::KIND_DEPOSIT,
            BrokerStatementParser::KIND_BUY,
            BrokerStatementParser::KIND_SELL,
            BrokerStatementParser::KIND_WITHDRAWAL,
        ], $kinds);
    }

    #[Test]
    public function a_us_date_is_read_month_first(): void
    {
        // 6/9/2002 is 9 June. Read day-first it becomes 6 September, moving a
        // trade by three months and silently corrupting every dated figure.
        $result = $this->parser->parseGrid($this->grid([
            ['B' => '6/9/2002', 'E' => 'Received from A CLIENT', 'F' => 'R/000002/057-058', 'I' => '10,000.00', 'J' => '10,000.00 CR'],
        ]));

        $this->assertSame('2002-06-09', $result['rows'][0]['occurred_on']);
    }

    #[Test]
    public function a_debit_balance_is_negative(): void
    {
        $result = $this->parser->parseGrid($this->grid([
            ['B' => '02/15/2001', 'E' => 'Receivable from client for purchase bill no. B/0000001/057-058 (TESTA 10 kitta @ 200.00)', 'F' => 'B/0000001/057-058', 'H' => '2,025.00', 'J' => '250.00 DR'],
        ]));

        $this->assertSame(-250.00, $result['rows'][0]['balance_after']);
    }

    #[Test]
    public function one_bill_settling_several_scrips_yields_one_trade_each(): void
    {
        $result = $this->parser->parseGrid($this->grid([
            ['B' => '03/15/2001', 'E' => 'Payable to client for sell bill no. S/0000002/057-058 (TESTA 10 kitta @ 300.00, TESTB 20 kitta @ 200.00, TESTC 30 kitta @ 100.00)', 'F' => 'S/0000002/057-058', 'I' => '9,925.00', 'J' => '9,925.00 CR'],
        ]));

        $this->assertCount(3, $result['trades']);
        $this->assertSame(['TESTA', 'TESTB', 'TESTC'], array_column($result['trades'], 'symbol'));
        $this->assertSame([10.0, 20.0, 30.0], array_column($result['trades'], 'quantity'));
        $this->assertSame([300.0, 200.0, 100.0], array_column($result['trades'], 'price'));
        $this->assertSame(['sell', 'sell', 'sell'], array_column($result['trades'], 'side'));
    }

    #[Test]
    public function a_bill_reference_is_never_mistaken_for_a_quantity(): void
    {
        // `B/0000002/057-058` is full of digits. Scanned naively, 2 reads
        // as a quantity and the real trade is lost.
        $result = $this->parser->parseGrid($this->grid([
            ['B' => '04/15/2001', 'E' => 'Receivable from client for purchase bill no. B/0000002/057-058 (TESTB 10 kitta @ 200.00)', 'F' => 'B/0000002/057-058', 'H' => '2,025.00', 'J' => '250.00 DR'],
        ]));

        $this->assertCount(1, $result['trades']);
        $this->assertSame('TESTB', $result['trades'][0]['symbol']);
        $this->assertSame(10.0, $result['trades'][0]['quantity']);
        $this->assertSame(200.00, $result['trades'][0]['price']);
    }

    #[Test]
    public function the_opening_balance_row_is_read_but_is_not_a_movement(): void
    {
        $result = $this->parser->parseGrid($this->grid([
            ['E' => 'Opening Balance', 'J' => '1,500.00'],
            ['B' => '01/15/2001', 'E' => 'Received from A CLIENT', 'F' => 'R/000001/057-058', 'I' => '10,000.00', 'J' => '11,500.00 CR'],
        ]));

        $this->assertSame(1500.0, $result['opening_balance']);
        $this->assertCount(1, $result['rows']);
    }

    #[Test]
    public function rows_replay_to_the_statements_own_closing_balance(): void
    {
        // The self-check the import depends on: money in minus money out must
        // land on the figure the broker printed.
        $result = $this->parser->parseGrid($this->grid([
            ['B' => '01/15/2001', 'E' => 'Received from A CLIENT', 'F' => 'R/000001/057-058', 'I' => '10,000.00', 'J' => '10,000.00 CR'],
            ['B' => '01/16/2001', 'E' => 'Receivable from client for purchase bill no. B/0000001/057-058 (TESTA 10 kitta @ 200.00)', 'F' => 'B/0000001/057-058', 'H' => '2,025.00', 'J' => '7,975.00 CR'],
            ['B' => '01/17/2001', 'E' => 'Payable to client for sell bill no. S/0000001/057-058 (TESTA 10 kitta @ 300.00)', 'F' => 'S/0000001/057-058', 'I' => '2,975.00', 'J' => '10,950.00 CR'],
        ]));

        $this->assertSame(10950.00, $result['closing_balance']);
        $this->assertSame(
            10950.00,
            $this->parser->replayBalance($result['rows'], $result['opening_balance']),
        );
    }

    #[Test]
    public function an_unclassifiable_row_is_skipped_with_a_warning_not_guessed(): void
    {
        $result = $this->parser->parseGrid($this->grid([
            ['B' => '01/15/2001', 'E' => 'Some narration nobody anticipated', 'F' => 'ZZ/999', 'I' => '100.00', 'J' => '100.00 CR'],
        ]));

        $this->assertSame([], $result['rows']);
        $this->assertNotSame([], $result['warnings']);
    }

    #[Test]
    public function a_row_with_no_readable_date_is_skipped_rather_than_dated_today(): void
    {
        $result = $this->parser->parseGrid($this->grid([
            ['B' => '', 'E' => 'Received from A CLIENT', 'F' => 'R/000001/057-058', 'I' => '10,000.00'],
        ]));

        $this->assertSame([], $result['rows']);
        $this->assertNotSame([], $result['warnings']);
    }

    #[Test]
    public function a_grid_without_the_expected_columns_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->parser->parseGrid([
            1 => ['A' => 'Symbol', 'B' => 'Quantity', 'C' => 'WACC'],
            2 => ['A' => 'NABIL', 'B' => '20', 'C' => '300.50'],
        ]);
    }

    #[Test]
    public function a_deposit_carries_no_trade(): void
    {
        $result = $this->parser->parseGrid($this->grid([
            ['B' => '01/15/2001', 'E' => 'Received from A CLIENT against bills B/0000003/057-058', 'F' => 'R/000001/057-058', 'I' => '10,000.00', 'J' => '10,000.00 CR'],
        ]));

        $this->assertCount(1, $result['rows']);
        $this->assertSame([], $result['trades']);
    }
}
