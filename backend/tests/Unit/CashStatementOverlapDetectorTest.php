<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Import\CashStatementImportService;
use App\Services\Import\CashStatementOverlapDetector;
use App\Services\Import\CashStatementParser;
use PHPUnit\Framework\TestCase;

class CashStatementOverlapDetectorTest extends TestCase
{
    /**
     * @return array{fingerprint: ?string, occurred_on: ?string, amount: ?float, direction: ?string}
     */
    private function row(string $fingerprint, string $date, float $amount, string $direction = 'in'): array
    {
        return [
            'fingerprint' => $fingerprint,
            'occurred_on' => $date,
            'amount' => $amount,
            'direction' => $direction,
        ];
    }

    public function test_the_same_transaction_id_twice_is_a_duplicate_and_is_skipped(): void
    {
        $already = [$this->row('fp-a', '2026-09-07', 100000.00)];
        $again = [
            $this->row('fp-a', '2026-09-07', 100000.00),
            $this->row('fp-b', '2026-06-22', 500.00),
        ];

        $result = CashStatementOverlapDetector::detect($again, $already);

        $this->assertArrayHasKey('fp-a', $result['duplicates']);
        $this->assertArrayNotHasKey('fp-b', $result['duplicates']);
        $this->assertMatchesRegularExpression('/already imported/i', $result['duplicates']['fp-a']);
    }

    public function test_re_importing_a_whole_statement_changes_nothing(): void
    {
        $rows = [
            $this->row('fp-a', '2026-09-07', 100000.00),
            $this->row('fp-b', '2026-06-22', 500.00),
            $this->row('fp-c', '2026-02-23', 1272.00, 'out'),
        ];

        $result = CashStatementOverlapDetector::detect($rows, $rows);

        $this->assertCount(3, $result['duplicates']);
        $this->assertSame([], $result['collisions']);
    }

    public function test_a_same_day_same_amount_movement_is_flagged_not_silently_taken(): void
    {
        // Different identity, same money on the same day: might be a genuine
        // second top-up, might be the same one re-exported without its id.
        $already = [$this->row('fp-old', '2025-03-19', 100000.00)];
        $candidates = [$this->row('fp-new', '2025-03-19', 100000.00)];

        $result = CashStatementOverlapDetector::detect($candidates, $already);

        $this->assertSame([], $result['duplicates']);
        $this->assertArrayHasKey('fp-new', $result['collisions']);
        $this->assertMatchesRegularExpression('/include it explicitly/i', $result['collisions']['fp-new']);
    }

    public function test_direction_is_part_of_the_collision_test(): void
    {
        // A 1,272.00 deposit and a 1,272.00 withdrawal on one day are not the
        // same movement, and flagging them as one would be noise.
        $already = [$this->row('fp-in', '2026-02-23', 1272.00, 'in')];
        $candidates = [$this->row('fp-out', '2026-02-23', 1272.00, 'out')];

        $this->assertSame([], CashStatementOverlapDetector::detect($candidates, $already)['collisions']);
    }

    public function test_two_statements_covering_the_same_stretch_raise_a_range_warning(): void
    {
        $already = [
            $this->row('fp-1', '2024-01-10', 25000.00),
            $this->row('fp-2', '2024-08-01', 6935.24, 'out'),
        ];
        $candidates = [
            $this->row('fp-3', '2024-06-15', 68000.00),
            $this->row('fp-4', '2025-03-19', 100000.00),
        ];

        $result = CashStatementOverlapDetector::detect($candidates, $already);

        $this->assertNotNull($result['range_overlap']);
        $this->assertSame('2024-06-15', $result['range_overlap']['from']);
        $this->assertSame('2024-08-01', $result['range_overlap']['to']);
        $this->assertSame(1, $result['range_overlap']['existing_rows']);
        $this->assertMatchesRegularExpression('/double-count the capital/i', implode(' ', $result['warnings']));
    }

    /**
     * The user's two real statements are sequential, not duplicated: the
     * account statement runs 2023-12 to 2025-03 and the TMS log 2026-02 to
     * 2026-09. That must import cleanly — but the detector arrives at it by
     * comparing the windows, never by knowing those dates.
     */
    public function test_sequential_statements_for_one_account_produce_no_warning(): void
    {
        $accountStatement = [
            $this->row('fp-b1', '2023-12-26', 15000.00),
            $this->row('fp-b2', '2024-07-12', 11407.17, 'out'),
            $this->row('fp-b3', '2025-03-19', 100000.00),
        ];
        $tmsLog = [
            $this->row('fp-a1', '2026-02-23', 1272.00, 'out'),
            $this->row('fp-a2', '2026-06-22', 500.00),
            $this->row('fp-a3', '2026-09-07', 100000.00),
        ];

        $result = CashStatementOverlapDetector::detect($tmsLog, $accountStatement);

        $this->assertNull($result['range_overlap']);
        $this->assertSame([], $result['duplicates']);
        $this->assertSame([], $result['collisions']);
        $this->assertSame([], $result['warnings']);

        $this->assertSame(['from' => '2026-02-23', 'to' => '2026-09-07'], $result['candidate_window']);
        $this->assertSame(['from' => '2023-12-26', 'to' => '2025-03-19'], $result['existing_window']);
    }

    public function test_the_residual_balance_carried_across_the_seam_is_not_a_duplicate(): void
    {
        // The account statement closes at a 1,272.29 credit balance; the TMS
        // log opens by withdrawing 1,272.00. Same money, different movements,
        // three years apart — nothing here may suppress the second.
        $accountStatement = [$this->row('fp-close', '2025-03-19', 1272.29)];
        $tmsLog = [$this->row('fp-quick-withdraw', '2026-02-23', 1272.00, 'out')];

        $result = CashStatementOverlapDetector::detect($tmsLog, $accountStatement);

        $this->assertSame([], $result['duplicates']);
        $this->assertSame([], $result['collisions']);
    }

    public function test_a_row_too_incomplete_to_identify_is_not_compared(): void
    {
        $candidates = [[
            'fingerprint' => null,
            'occurred_on' => '2024-07-12',
            'amount' => 11407.17,
            'direction' => null,
        ]];

        $result = CashStatementOverlapDetector::detect(
            $candidates,
            [$this->row('fp-x', '2024-07-12', 11407.17, 'out')],
        );

        $this->assertSame([], $result['duplicates']);
        $this->assertSame([], $result['collisions']);
    }

    public function test_nothing_previously_imported_means_nothing_to_warn_about(): void
    {
        $result = CashStatementOverlapDetector::detect(
            [$this->row('fp-a', '2026-09-07', 100000.00)],
            [],
        );

        $this->assertNull($result['range_overlap']);
        $this->assertNull($result['existing_window']);
        $this->assertSame([], $result['warnings']);
    }

    // ------------------------------------------------------------------
    // Commit ordering and the back-dated overdraw
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function planRow(int $line, string $date, float $signed): array
    {
        return ['line' => $line, 'occurred_on' => $date, 'signed_amount' => $signed];
    }

    public function test_rows_are_committed_oldest_first_whatever_order_the_file_lists_them(): void
    {
        // The TMS log exports newest first, which would replay a withdrawal
        // before the deposits that funded it.
        $plan = CashStatementImportService::plan([
            $this->planRow(1, '2026-09-07', 100000.00),
            $this->planRow(2, '2026-02-23', -1272.00),
            $this->planRow(3, '2026-06-22', 500.00),
        ], 1500.00);

        $this->assertSame(
            ['2026-02-23', '2026-06-22', '2026-09-07'],
            array_column($plan['ordered'], 'occurred_on'),
        );
        $this->assertNull($plan['shortfall']);
        $this->assertEqualsWithDelta(100728.00, $plan['ending'], 0.001);
    }

    public function test_a_statement_that_starts_mid_history_is_caught_before_anything_is_written(): void
    {
        // Opens by withdrawing a balance carried in from an earlier statement
        // this portfolio has not imported yet.
        $plan = CashStatementImportService::plan([
            $this->planRow(1, '2026-02-23', -1272.00),
            $this->planRow(2, '2026-09-07', 100000.00),
        ], 0.0);

        $this->assertNotNull($plan['shortfall']);
        $this->assertSame('2026-02-23', $plan['shortfall']['occurred_on']);
        $this->assertEqualsWithDelta(1272.00, $plan['shortfall']['short_by'], 0.001);
        $this->assertEqualsWithDelta(0.0, $plan['shortfall']['balance'], 0.001);
    }

    public function test_the_carried_in_balance_resolves_the_shortfall(): void
    {
        $rows = [
            $this->planRow(1, '2026-02-23', -1272.00),
            $this->planRow(2, '2026-09-07', 100000.00),
        ];

        // Same rows, with the 1,272.29 the earlier statement closed at.
        $plan = CashStatementImportService::plan($rows, 1272.29);

        $this->assertNull($plan['shortfall']);
        $this->assertEqualsWithDelta(100000.29, $plan['ending'], 0.001);
    }

    public function test_a_coherent_statement_never_dips_negative_on_its_own(): void
    {
        // Six deposits and three withdrawals in real date order: every
        // withdrawal is funded by deposits that precede it.
        $plan = CashStatementImportService::plan([
            $this->planRow(1, '2023-12-26', 15000.00),
            $this->planRow(2, '2024-06-03', 31000.00),
            $this->planRow(3, '2024-06-15', 68000.00),
            $this->planRow(4, '2024-07-12', -11407.17),
            $this->planRow(5, '2024-07-21', 500.00),
            $this->planRow(6, '2024-07-24', 100000.00),
            $this->planRow(7, '2024-08-01', -6935.24),
            $this->planRow(8, '2024-11-18', -14425.21),
            $this->planRow(9, '2025-03-19', 100000.00),
        ], 0.0);

        $this->assertNull($plan['shortfall']);
        $this->assertEqualsWithDelta(281732.38, $plan['ending'], 0.001);
    }

    public function test_the_direction_constants_the_detector_compares_on_are_the_parsers(): void
    {
        // Guards against the two classes drifting apart on the string 'out'.
        $this->assertSame('out', CashStatementParser::DIRECTION_OUT);
        $this->assertSame('in', CashStatementParser::DIRECTION_IN);
    }
}
