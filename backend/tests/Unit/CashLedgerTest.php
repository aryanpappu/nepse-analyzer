<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Portfolio\CashLedger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CashLedger::replay() is the pure core behind amend()/void(): given the
 * surviving (non-voided) rows for one account, in ledger order, it produces
 * the running balance after each row plus the ending balance. Everything
 * else those two operations do — locking, persisting, deciding which rows
 * are affected — is a thin, DB-touching shell around this function, so it
 * is exercised here directly with no database involved.
 */
final class CashLedgerTest extends TestCase
{
    #[Test]
    public function it_returns_zero_balance_for_no_rows(): void
    {
        $result = CashLedger::replay([]);

        $this->assertSame([], $result['balances']);
        $this->assertSame(0.0, $result['ending']);
    }

    #[Test]
    public function it_accumulates_a_running_balance_in_order(): void
    {
        $result = CashLedger::replay([
            ['id' => 1, 'amount' => 500000.0],
            ['id' => 2, 'amount' => -100000.0],
            ['id' => 3, 'amount' => 25000.0],
        ]);

        $this->assertSame(500000.0, $result['balances'][1]);
        $this->assertSame(400000.0, $result['balances'][2]);
        $this->assertSame(425000.0, $result['balances'][3]);
        $this->assertSame(425000.0, $result['ending']);
    }

    #[Test]
    public function removing_a_mis_entered_row_recomputes_every_later_balance(): void
    {
        // Ledger before a correction: a fat-fingered 500,000 deposit sits
        // between two real entries.
        $before = CashLedger::replay([
            ['id' => 1, 'amount' => 100000.0],
            ['id' => 2, 'amount' => 500000.0], // the mistake
            ['id' => 3, 'amount' => -20000.0],
        ]);

        $this->assertSame(580000.0, $before['ending']);

        // After voiding row 2, the surviving rows are replayed again — row 3's
        // balance_after must drop, not stay stale at 580,000.
        $after = CashLedger::replay([
            ['id' => 1, 'amount' => 100000.0],
            ['id' => 3, 'amount' => -20000.0],
        ]);

        $this->assertSame(100000.0, $after['balances'][1]);
        $this->assertSame(80000.0, $after['balances'][3]);
        $this->assertSame(80000.0, $after['ending']);
        $this->assertArrayNotHasKey(2, $after['balances']);
    }

    #[Test]
    public function amending_an_amount_reflows_every_row_after_it(): void
    {
        // Row 2 was entered as 500,000 by mistake; it should have been 50,000.
        $corrected = CashLedger::replay([
            ['id' => 1, 'amount' => 100000.0],
            ['id' => 2, 'amount' => 50000.0],
            ['id' => 3, 'amount' => -20000.0],
        ]);

        $this->assertSame(150000.0, $corrected['balances'][2]);
        $this->assertSame(130000.0, $corrected['balances'][3]);
        $this->assertSame(130000.0, $corrected['ending']);
    }

    #[Test]
    public function it_rounds_to_two_decimal_places_at_every_step(): void
    {
        $result = CashLedger::replay([
            ['id' => 1, 'amount' => 10.005],
            ['id' => 2, 'amount' => 0.001],
        ]);

        // 10.005 rounds to 10.01 (half-up) before accumulating, and the
        // running sum is re-rounded after each step so drift cannot creep in.
        $this->assertSame(10.01, $result['balances'][1]);
        $this->assertSame(10.01, $result['balances'][2]);
    }

    #[Test]
    public function string_keys_are_preserved_as_the_balance_index(): void
    {
        $result = CashLedger::replay([
            ['id' => 'a', 'amount' => 10.0],
            ['id' => 'b', 'amount' => -4.0],
        ]);

        $this->assertSame(10.0, $result['balances']['a']);
        $this->assertSame(6.0, $result['balances']['b']);
    }
}
