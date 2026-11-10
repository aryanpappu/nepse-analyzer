<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Import\CashStatementParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CashStatementParserTest extends TestCase
{
    private CashStatementParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CashStatementParser;
    }

    /**
     * Fictional Format A data matching the TMS layout, including the two
     * FAILED top-ups and the inconsistent `SUCCESS` / `Success` casing.
     */
    private function tmsLog(): string
    {
        return <<<'LOG'
        00000000-0000-4000-8000-000000000001  Deposit  1,000.00  Esewa  9/8/2002, 10:00:00 AM  SUCCESS
        00000000-0000-4000-8000-000000000002  Deposit  1000.00      Esewa  6/12/2002, 11:00:00 AM  SUCCESS
        00000000-0000-4000-8000-000000000003  Deposit  1,000.00    ConnectIps  3/2/2002, 9:00:00 AM    SUCCESS
        00000000-0000-4000-8000-000000000004  Withdraw (Quick Withdraw)  1,000.00  Bank  2/13/2002, 2:00:00 PM  Success
        00000000-0000-4000-8000-000000000005  Deposit  200.00    ConnectIps  1/15/2002, 8:00:00 PM   FAILED
        00000000-0000-4000-8000-000000000006  Deposit  300.00   ConnectIps  10/4/2001, 7:00:00 PM  FAILED
        LOG;
    }

    /**
     * Fictional Format B data matching a narrative account statement.
     * The withdrawal block carries no direction word on the rows themselves —
     * only the heading says which way the money went.
     */
    private function ledgerStatement(): string
    {
        return <<<'LEDGER'
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
        LEDGER;
    }

    // ------------------------------------------------------------------
    // Format detection
    // ------------------------------------------------------------------

    public function test_it_tells_the_two_statement_formats_apart(): void
    {
        $this->assertSame(CashStatementParser::FORMAT_TMS, $this->parser->detectFormat($this->tmsLog()));
        $this->assertSame(CashStatementParser::FORMAT_LEDGER, $this->parser->detectFormat($this->ledgerStatement()));
    }

    public function test_it_refuses_a_file_that_is_not_a_cash_statement(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not look like a cash statement/');

        $this->parser->detectFormat("Scrip,Quantity\nNABIL,20");
    }

    // ------------------------------------------------------------------
    // Format A
    // ------------------------------------------------------------------

    public function test_failed_movements_are_excluded_with_a_reason(): void
    {
        $rows = $this->parser->parseTmsLog($this->tmsLog())['rows'];

        $failed = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['status'] === CashStatementParser::ROW_EXCLUDED_FAILED,
        ));

        $this->assertCount(2, $failed, 'Both FAILED top-ups must be excluded.');

        // Failed top-ups of 200 + 300 would overstate capital by 500.
        $this->assertEqualsWithDelta(500.0, array_sum(array_column($failed, 'amount')), 0.001);

        foreach ($failed as $row) {
            $this->assertFalse($row['include']);
            $this->assertMatchesRegularExpression('/no money moved/i', (string) $row['reason']);
        }

        $included = array_values(array_filter($rows, static fn (array $row): bool => $row['include']));
        $this->assertCount(4, $included);
    }

    public function test_success_and_success_are_both_read_as_settled(): void
    {
        $rows = $this->parser->parseTmsLog($this->tmsLog())['rows'];
        $byId = array_column($rows, null, 'external_id');

        // Upper-case SUCCESS ...
        $this->assertSame(
            CashStatementParser::SETTLED_SUCCESS,
            $byId['00000000-0000-4000-8000-000000000001']['settlement'],
        );

        // ... and mixed-case Success, on the withdrawal row.
        $this->assertSame(
            CashStatementParser::SETTLED_SUCCESS,
            $byId['00000000-0000-4000-8000-000000000004']['settlement'],
        );
        $this->assertTrue($byId['00000000-0000-4000-8000-000000000004']['include']);
    }

    public function test_dates_are_read_month_first(): void
    {
        $rows = $this->parser->parseTmsLog($this->tmsLog())['rows'];
        $byId = array_column($rows, null, 'external_id');

        // 9/8/2002 is 8 September, not 9 August.
        $this->assertSame('2002-09-08', $byId['00000000-0000-4000-8000-000000000001']['occurred_on']);

        // 10/4/2001 is 4 October, not 10 April.
        $this->assertSame('2001-10-04', $byId['00000000-0000-4000-8000-000000000006']['occurred_on']);

        // 3/2/2002 is 2 March, not 3 February.
        $this->assertSame('2002-03-02', $byId['00000000-0000-4000-8000-000000000003']['occurred_on']);
    }

    /**
     * The ambiguous case the whole feature turns on: both fields are valid
     * months and valid days, so nothing but an explicit month-first rule can
     * decide it. Read as 6 September, every money-weighted figure moves.
     */
    public function test_a_day_month_ambiguous_date_is_read_as_june_the_ninth(): void
    {
        $row = $this->parser->normalizeTmsRow(
            '00000000-0000-4000-8000-000000000007 Deposit 2,500.00 Esewa 6/9/2002, 1:15:00 PM SUCCESS',
        );

        $this->assertNotNull($row);
        $this->assertSame('2002-06-09', $row['occurred_on']);
        $this->assertSame('2002-06-09 13:15:00', $row['occurred_at']);
    }

    public function test_midnight_and_noon_do_not_collide(): void
    {
        $midnight = $this->parser->extractUsDateTime('1/15/2002, 12:30:00 AM');
        $noon = $this->parser->extractUsDateTime('1/15/2002, 12:30:00 PM');

        $this->assertSame('2002-01-15 00:30:00', $midnight['datetime']);
        $this->assertSame('2002-01-15 12:30:00', $noon['datetime']);
    }

    public function test_thousands_separators_survive_parsing(): void
    {
        $rows = $this->parser->parseTmsLog($this->tmsLog())['rows'];
        $byId = array_column($rows, null, 'external_id');

        // (float) '1,000.00' is 1.0 — the failure this guards against.
        $this->assertEqualsWithDelta(1000.00, $byId['00000000-0000-4000-8000-000000000001']['amount'], 0.001);
        $this->assertEqualsWithDelta(1000.00, $byId['00000000-0000-4000-8000-000000000003']['amount'], 0.001);
        $this->assertEqualsWithDelta(1000.00, $byId['00000000-0000-4000-8000-000000000004']['amount'], 0.001);

        // A plain amount with no separator still parses.
        $this->assertEqualsWithDelta(1000.00, $byId['00000000-0000-4000-8000-000000000002']['amount'], 0.001);
    }

    public function test_quick_withdraw_is_classified_as_a_withdrawal(): void
    {
        $rows = $this->parser->parseTmsLog($this->tmsLog())['rows'];
        $byId = array_column($rows, null, 'external_id');
        $row = $byId['00000000-0000-4000-8000-000000000004'];

        $this->assertSame(CashStatementParser::DIRECTION_OUT, $row['direction']);
        $this->assertEqualsWithDelta(-1000.00, $row['signed_amount'], 0.001);
    }

    public function test_every_withdrawal_variant_matches_on_a_prefix_not_an_exact_string(): void
    {
        foreach (['Withdraw', 'Withdraw (Quick Withdraw)', 'Quick Withdrawal', 'WITHDRAW (INSTANT)'] as $label) {
            $this->assertSame(
                CashStatementParser::DIRECTION_OUT,
                $this->parser->direction($label),
                "[{$label}] should read as a withdrawal.",
            );
        }
    }

    public function test_the_transaction_id_is_the_idempotency_key(): void
    {
        $rows = $this->parser->parseTmsLog($this->tmsLog())['rows'];

        $fingerprints = array_column($rows, 'fingerprint');
        $this->assertCount(6, array_unique($fingerprints), 'Six distinct movements, six distinct keys.');

        // The key depends only on the id, so a re-export with a reformatted
        // amount or a different column order still collides.
        $this->assertSame(
            $this->parser->fingerprint('00000000-0000-4000-8000-00000000000A', null, null, null, null),
            $this->parser->fingerprint('00000000-0000-4000-8000-00000000000a', '2002-09-08', 1000.0, 'in', null),
        );
    }

    public function test_a_transaction_id_repeated_inside_one_file_is_counted_once(): void
    {
        $log = $this->tmsLog()."\n"
            .'00000000-0000-4000-8000-000000000001  Deposit  1,000.00  Esewa  9/8/2002, 10:00:00 AM  SUCCESS';

        $parsed = $this->parser->parseTmsLog($log);
        $included = array_values(array_filter($parsed['rows'], static fn (array $row): bool => $row['include']));

        $this->assertCount(7, $parsed['rows'], 'The repeat is still shown.');
        $this->assertCount(4, $included, 'But it is not counted twice.');
        $this->assertEqualsWithDelta(
            1000.00 + 1000.00 + 1000.00,
            array_sum(array_map(
                static fn (array $row): float => $row['direction'] === 'in' ? (float) $row['amount'] : 0.0,
                $included,
            )),
            0.001,
        );
    }

    public function test_a_header_row_is_not_read_as_a_movement(): void
    {
        $log = "Transaction Id,Type,Amount,Channel,Date,Status\n".$this->tmsLog();

        $this->assertCount(6, $this->parser->parseTmsLog($log)['rows']);
    }

    public function test_the_same_log_parses_the_same_whether_it_is_csv_or_pasted_text(): void
    {
        $csv = '"00000000-0000-4000-8000-000000000001","Deposit","1,000.00","Esewa","9/8/2002, 10:00:00 AM","SUCCESS"';

        $fromCsv = $this->parser->normalizeTmsRow($csv);
        $fromText = $this->parser->normalizeTmsRow(
            '00000000-0000-4000-8000-000000000001  Deposit  1,000.00  Esewa  9/8/2002, 10:00:00 AM  SUCCESS',
        );

        // Fields are found by pattern, so column order and delimiter cannot
        // silently shift the amount into the channel.
        $this->assertSame($fromText['occurred_on'], $fromCsv['occurred_on']);
        $this->assertSame($fromText['direction'], $fromCsv['direction']);
        $this->assertSame($fromText['fingerprint'], $fromCsv['fingerprint']);
        $this->assertEqualsWithDelta($fromText['amount'], $fromCsv['amount'], 0.001);
    }

    public function test_an_ambiguous_amount_is_flagged_rather_than_guessed(): void
    {
        $row = $this->parser->normalizeTmsRow(
            '00000000-0000-4000-8000-000000000007 Deposit 1,000.00 fee 25.00 Esewa 6/9/2002, 1:15:00 PM SUCCESS',
        );

        $this->assertSame(CashStatementParser::ROW_NEEDS_REVIEW, $row['status']);
        $this->assertFalse($row['include']);
        $this->assertNull($row['amount']);
    }

    public function test_a_pending_movement_is_held_back_until_it_settles(): void
    {
        $row = $this->parser->normalizeTmsRow(
            '00000000-0000-4000-8000-000000000007 Deposit 2,500.00 Esewa 6/9/2002, 1:15:00 PM PENDING',
        );

        $this->assertSame(CashStatementParser::SETTLED_PENDING, $row['settlement']);
        $this->assertFalse($row['include']);
    }

    // ------------------------------------------------------------------
    // Format B
    // ------------------------------------------------------------------

    public function test_the_narrative_statement_reads_deposits_and_withdrawals(): void
    {
        $rows = $this->parser->parseLedger($this->ledgerStatement())['rows'];

        $this->assertCount(9, $rows);

        $in = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['direction'] === CashStatementParser::DIRECTION_IN,
        ));
        $out = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['direction'] === CashStatementParser::DIRECTION_OUT,
        ));

        $this->assertCount(6, $in);
        $this->assertCount(3, $out);

        $this->assertEqualsWithDelta(6000.00, array_sum(array_column($in, 'amount')), 0.001);
        $this->assertEqualsWithDelta(1500.00, array_sum(array_column($out, 'amount')), 0.001);
    }

    public function test_a_heading_decides_direction_for_rows_that_do_not_say(): void
    {
        $rows = $this->parser->parseLedger($this->ledgerStatement())['rows'];
        $byDate = array_column($rows, null, 'occurred_on');

        // `07/13/2001  250.00  S/0000001/057-058` carries no direction
        // word at all — only the "Payment made for" heading above it does.
        $this->assertSame(CashStatementParser::DIRECTION_OUT, $byDate['2001-07-13']['direction']);
        $this->assertEqualsWithDelta(250.00, $byDate['2001-07-13']['amount'], 0.001);
        $this->assertEqualsWithDelta(-250.00, $byDate['2001-07-13']['signed_amount'], 0.001);
    }

    public function test_a_row_with_no_direction_at_all_is_sent_to_review_not_guessed(): void
    {
        // No heading above it, no direction phrase in it.
        $parsed = $this->parser->parseLedger("07/13/2001   250.00   S/0000001/057-058\n");

        $this->assertSame(CashStatementParser::ROW_NEEDS_REVIEW, $parsed['rows'][0]['status']);
        $this->assertFalse($parsed['rows'][0]['include']);
        $this->assertNull($parsed['rows'][0]['direction']);
    }

    public function test_bill_references_are_kept_and_do_not_become_the_amount(): void
    {
        $rows = $this->parser->parseLedger($this->ledgerStatement())['rows'];
        $byDate = array_column($rows, null, 'occurred_on');

        // `B/0000002` must not be read as 2.
        $this->assertEqualsWithDelta(1000.00, $byDate['2001-02-04']['amount'], 0.001);
        $this->assertSame('B/0000001, B/0000002', $byDate['2001-02-04']['reference']);

        // A memo's bare number ("(collateral 3)") must not either.
        $this->assertEqualsWithDelta(1000.00, $byDate['2001-03-16']['amount'], 0.001);
        $this->assertSame('S/0000001/057-058', $byDate['2001-07-13']['reference']);
    }

    public function test_dates_in_the_narrative_statement_are_also_month_first(): void
    {
        $rows = $this->parser->parseLedger($this->ledgerStatement())['rows'];
        $dates = array_column($rows, 'occurred_on');

        $this->assertContains('2001-01-16', $dates);
        $this->assertContains('2001-02-04', $dates);   // 4 February, not 2 April
        $this->assertContains('2001-09-19', $dates);
        $this->assertContains('2001-06-20', $dates);
    }

    public function test_a_movement_without_a_transaction_id_is_keyed_on_its_own_facts(): void
    {
        $rows = $this->parser->parseLedger($this->ledgerStatement())['rows'];

        $this->assertCount(9, array_unique(array_column($rows, 'fingerprint')));

        foreach ($rows as $row) {
            $this->assertNull($row['external_id']);
            $this->assertNotNull($row['fingerprint']);
        }
    }

    // ------------------------------------------------------------------
    // Privacy
    // ------------------------------------------------------------------

    public function test_institution_names_are_reduced_to_a_payment_rail(): void
    {
        $rows = $this->parser->parseLedger($this->ledgerStatement())['rows'];

        foreach ($rows as $row) {
            // "Example Bank Corporate" / "Sample Bank" both become `bank`.
            $this->assertContains($row['channel'], ['bank', 'other']);
            $this->assertStringNotContainsStringIgnoringCase('sample', (string) $row['narration']);
            $this->assertStringNotContainsStringIgnoringCase('example bank', (string) $row['channel']);
        }

        $tms = $this->parser->parseTmsLog($this->tmsLog())['rows'];
        $this->assertSame(
            ['esewa', 'esewa', 'connect_ips', 'bank', 'connect_ips', 'connect_ips'],
            array_column($tms, 'channel'),
        );
    }

    public function test_personal_names_and_account_numbers_never_reach_storage(): void
    {
        $redacted = $this->parser->redact('Received from SYNTHETIC PERSON, a/c 9999000000001234 (DemoMemo1)');

        $this->assertStringNotContainsStringIgnoringCase('synthetic', $redacted);
        $this->assertStringNotContainsString('9999000000001234', $redacted);
        $this->assertStringContainsString('[name withheld]', $redacted);

        // Same restraint the MeroShare importer applies to a demat number:
        // the last four digits are all that is ever kept.
        $this->assertStringContainsString('****1234', $redacted);
    }

    public function test_parenthetical_memos_are_dropped_from_stored_narration(): void
    {
        $rows = $this->parser->parseLedger($this->ledgerStatement())['rows'];
        $byDate = array_column($rows, null, 'occurred_on');

        $this->assertStringNotContainsStringIgnoringCase('demomemo1', (string) $byDate['2001-05-25']['narration']);
        $this->assertStringNotContainsStringIgnoringCase('collateral', (string) $byDate['2001-03-16']['narration']);
    }
}
