<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Import\CashStatementImportService;
use App\Services\Import\CashStatementParser;
use App\Services\Import\CashStatementReconciler;
use PHPUnit\Framework\TestCase;

class CashStatementReconcilerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function movement(
        string $date,
        float $amount,
        string $direction = 'in',
        bool $include = true,
        string $status = CashStatementImportService::ROW_READY,
    ): array {
        return [
            'occurred_on' => $date,
            'amount' => $amount,
            'direction' => $direction,
            'include' => $include,
            'status' => $status,
        ];
    }

    /**
     * Fictional cash history: nineteen deposits of 1,000.00 and four
     * withdrawals totalling 2,500.00. Failed top-ups never affect capital.
     *
     * @return list<array<string, mixed>>
     */
    private function syntheticHistory(): array
    {
        $rows = [];

        foreach ([
            '2001-01-16', '2001-02-04', '2001-03-16', '2001-04-22',
            '2001-05-25', '2001-06-20', '2002-03-02', '2002-06-12',
            '2002-09-08', '2001-07-15', '2001-08-15', '2001-09-15',
            '2001-10-15', '2001-11-15', '2001-12-15', '2002-01-15',
            '2002-02-15', '2002-04-15', '2002-05-15',
        ] as $date) {
            $rows[] = $this->movement($date, 1000.00);
        }

        $rows[] = $this->movement('2001-07-13', 250.00, 'out');
        $rows[] = $this->movement('2001-08-02', 500.00, 'out');
        $rows[] = $this->movement('2001-09-19', 750.00, 'out');
        $rows[] = $this->movement('2002-02-13', 1000.00, 'out');
        $rows[] = $this->movement('2002-01-15', 200.00, 'in', false, CashStatementImportService::ROW_EXCLUDED_FAILED);
        $rows[] = $this->movement('2001-10-04', 300.00, 'in', false, CashStatementImportService::ROW_EXCLUDED_FAILED);

        return $rows;
    }

    // ------------------------------------------------------------------

    public function test_it_reproduces_the_hand_reconciled_totals(): void
    {
        $totals = CashStatementReconciler::totals($this->syntheticHistory());

        $this->assertEqualsWithDelta(19000.00, $totals['money_in'], 0.005);
        $this->assertSame(19, $totals['deposits']);

        $this->assertEqualsWithDelta(2500.00, $totals['money_out'], 0.005);
        $this->assertSame(4, $totals['withdrawals']);

        $this->assertEqualsWithDelta(16500.00, $totals['net_injected'], 0.005);
        $this->assertSame(23, $totals['movements']);
    }

    public function test_the_failed_top_ups_are_reported_as_excluded_not_quietly_dropped(): void
    {
        $totals = CashStatementReconciler::totals($this->syntheticHistory());

        $excluded = $totals['excluded'][CashStatementImportService::ROW_EXCLUDED_FAILED];

        $this->assertSame(2, $excluded['count']);
        $this->assertEqualsWithDelta(500.00, $excluded['amount'], 0.005);

        // And they are nowhere in the capital.
        $this->assertEqualsWithDelta(19000.00, $totals['money_in'], 0.005);
    }

    public function test_it_reports_the_profit_and_simple_return_on_the_synthetic_capital(): void
    {
        $totals = CashStatementReconciler::totals($this->syntheticHistory());

        // Holdings marked to market, plus residual cash.
        $result = CashStatementReconciler::reconcile($totals, null, 18000.00);

        $this->assertEqualsWithDelta(16500.00, $result['net_injected'], 0.005);
        $this->assertEqualsWithDelta(1500.00, $result['profit'], 0.005);
        $this->assertEqualsWithDelta(9.09, $result['simple_return_percent'], 0.005);
    }

    public function test_the_movement_window_spans_both_statements(): void
    {
        $totals = CashStatementReconciler::totals($this->syntheticHistory());

        $this->assertSame('2001-01-16', $totals['first_movement_on']);
        $this->assertSame('2002-09-08', $totals['last_movement_on']);
    }

    // ------------------------------------------------------------------
    // Agreement with the trade ledger
    // ------------------------------------------------------------------

    public function test_capital_that_matches_the_trades_reads_as_agreement(): void
    {
        $totals = CashStatementReconciler::totals($this->syntheticHistory());

        // Trades consumed all but a rupee of the capital injected.
        $result = CashStatementReconciler::reconcile($totals, 16499.62, 18000.00);

        $this->assertTrue($result['agrees']);
        $this->assertEqualsWithDelta(0.38, $result['difference'], 0.005);
        $this->assertMatchesRegularExpression('/to within a rupee/i', $result['note']);
    }

    public function test_capital_the_trades_cannot_account_for_is_called_out(): void
    {
        $totals = CashStatementReconciler::totals($this->syntheticHistory());

        $result = CashStatementReconciler::reconcile($totals, 14000.00, 18000.00);

        $this->assertFalse($result['agrees']);
        $this->assertEqualsWithDelta(2500.00, $result['difference'], 0.005);
        $this->assertMatchesRegularExpression('/still\s+sitting as cash, or trades are missing/i', $result['note']);
    }

    public function test_trades_that_outrun_the_statement_suggest_a_missing_page(): void
    {
        $totals = CashStatementReconciler::totals($this->syntheticHistory());

        $result = CashStatementReconciler::reconcile($totals, 20000.00, 18000.00);

        $this->assertFalse($result['agrees']);
        $this->assertEqualsWithDelta(-3500.00, $result['difference'], 0.005);
        $this->assertMatchesRegularExpression('/page is probably missing/i', $result['note']);
    }

    public function test_with_no_trades_there_is_nothing_to_reconcile_against(): void
    {
        $result = CashStatementReconciler::reconcile(
            CashStatementReconciler::totals($this->syntheticHistory()),
            null,
            null,
        );

        $this->assertNull($result['agrees']);
        $this->assertNull($result['difference']);
        $this->assertMatchesRegularExpression('/no trades to reconcile against/i', $result['note']);
    }

    // ------------------------------------------------------------------
    // n/a, never zero
    // ------------------------------------------------------------------

    public function test_an_unpriceable_portfolio_reports_no_profit_rather_than_zero(): void
    {
        $result = CashStatementReconciler::reconcile(
            CashStatementReconciler::totals($this->syntheticHistory()),
            16499.62,
            null,
        );

        $this->assertNull($result['profit']);
        $this->assertNull($result['simple_return_percent']);
        $this->assertMatchesRegularExpression('/no price/i', $result['note']);
    }

    public function test_no_capital_means_no_return_rather_than_a_flat_one(): void
    {
        $result = CashStatementReconciler::reconcile(
            CashStatementReconciler::totals([]),
            null,
            0.0,
        );

        $this->assertEqualsWithDelta(0.0, $result['net_injected'], 0.005);
        $this->assertNull($result['simple_return_percent']);
    }

    public function test_a_statement_that_only_takes_money_out_nets_negative(): void
    {
        $totals = CashStatementReconciler::totals([
            $this->movement('2001-07-13', 250.00, 'out'),
            $this->movement('2001-08-02', 500.00, 'out'),
        ]);

        $this->assertEqualsWithDelta(0.0, $totals['money_in'], 0.005);
        $this->assertEqualsWithDelta(750.00, $totals['money_out'], 0.005);
        $this->assertEqualsWithDelta(-750.00, $totals['net_injected'], 0.005);

        // Nothing was injected, so there is no base to express a return on.
        $this->assertNull(CashStatementReconciler::reconcile($totals, null, 5000.0)['simple_return_percent']);
    }

    // ------------------------------------------------------------------
    // End to end, from the raw statements
    // ------------------------------------------------------------------

    /**
     * The parser's own output, reconciled — so the totals are not merely
     * arithmetic over hand-built arrays.
     */
    public function test_the_parsed_statements_reconcile_to_the_same_figures(): void
    {
        $parser = new CashStatementParser;

        $tms = $parser->parseTmsLog(<<<'LOG'
        00000000-0000-4000-8000-000000000001  Deposit  1,000.00  Esewa  9/8/2002, 10:00:00 AM  SUCCESS
        00000000-0000-4000-8000-000000000002  Deposit  1000.00      Esewa  6/12/2002, 11:00:00 AM  SUCCESS
        00000000-0000-4000-8000-000000000003  Deposit  1,000.00    ConnectIps  3/2/2002, 9:00:00 AM    SUCCESS
        00000000-0000-4000-8000-000000000004  Withdraw (Quick Withdraw)  1,000.00  Bank  2/13/2002, 2:00:00 PM  Success
        00000000-0000-4000-8000-000000000005  Deposit  200.00    ConnectIps  1/15/2002, 8:00:00 PM   FAILED
        00000000-0000-4000-8000-000000000006  Deposit  300.00   ConnectIps  10/4/2001, 7:00:00 PM  FAILED
        LOG)['rows'];

        $totals = CashStatementReconciler::totals($tms);

        // Three fictional deposits; the 500.00 of failed top-ups is excluded.
        $this->assertEqualsWithDelta(3000.00, $totals['money_in'], 0.005);
        $this->assertSame(3, $totals['deposits']);
        $this->assertEqualsWithDelta(1000.00, $totals['money_out'], 0.005);
        $this->assertSame(1, $totals['withdrawals']);
        $this->assertEqualsWithDelta(2000.00, $totals['net_injected'], 0.005);

        $ledger = $parser->parseLedger(<<<'LEDGER'
        01/16/2001   1,000.00   On Accounts (self)                                  Example Bank Corporate
        02/04/2001   1,000.00   Against bills B/0000001, B/0000002 (Collateral2)    Sample Bank
        03/16/2001   1,000.00   Against bill B/0000002 (collateral 3)               Sample Bank
        04/22/2001      1000.00   Against bill B/0000003 (colpending)                  Sample Bank
        05/25/2001  1,000.00   Against bills B/0000003, B/0000004 (DemoMemo1)         Sample Bank
        06/20/2001  1,000.00   Against bills B/0000005, B/0000006, B/0000007        Sample Bank
        --- withdrawals ("Payment made for") ---
        07/13/2001   250.00   S/0000001/057-058
        08/02/2001    500.00   S/0000002/057-058
        09/19/2001   750.00   S/0000003/057-058
        LEDGER)['rows'];

        $ledgerTotals = CashStatementReconciler::totals($ledger);

        $this->assertEqualsWithDelta(6000.00, $ledgerTotals['money_in'], 0.005);
        $this->assertEqualsWithDelta(1500.00, $ledgerTotals['money_out'], 0.005);

        // Together the two statements account for 9 of the 19 deposits and
        // all 4 withdrawals in the fictional statements.
        $this->assertSame(9, $totals['deposits'] + $ledgerTotals['deposits']);
        $this->assertSame(4, $totals['withdrawals'] + $ledgerTotals['withdrawals']);
        $this->assertEqualsWithDelta(
            2500.00,
            $totals['money_out'] + $ledgerTotals['money_out'],
            0.005,
        );
    }
}
