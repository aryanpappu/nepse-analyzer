<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scraper\Backfill\BackfillBudget;
use App\Services\Scraper\Backfill\BackfillCandidate;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class BackfillBudgetTest extends TestCase
{
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-09-09 10:00:00');
    }

    public function test_it_excludes_complete_and_exhausted_symbols(): void
    {
        $budget = new BackfillBudget(symbolsPerRun: 10, retryAfterHours: 20);

        $candidates = [
            new BackfillCandidate(1, 'AAA', 'pending'),
            new BackfillCandidate(2, 'BBB', 'complete'),
            new BackfillCandidate(3, 'CCC', 'exhausted'),
            new BackfillCandidate(4, 'DDD', 'active'),
        ];

        $selected = $budget->select($candidates, $this->now);

        $this->assertSame(['AAA', 'DDD'], array_map(static fn (BackfillCandidate $c): string => $c->symbol, $selected));
    }

    public function test_it_caps_at_the_configured_budget(): void
    {
        $budget = new BackfillBudget(symbolsPerRun: 2, retryAfterHours: 20);

        $candidates = [
            new BackfillCandidate(1, 'AAA', 'pending'),
            new BackfillCandidate(2, 'BBB', 'pending'),
            new BackfillCandidate(3, 'CCC', 'pending'),
        ];

        $selected = $budget->select($candidates, $this->now);

        $this->assertCount(2, $selected);
    }

    public function test_never_attempted_symbols_are_picked_before_previously_attempted_ones(): void
    {
        $budget = new BackfillBudget(symbolsPerRun: 1, retryAfterHours: 0);

        $candidates = [
            new BackfillCandidate(1, 'OLD', 'active', $this->now->copy()->subHours(48)),
            new BackfillCandidate(2, 'NEW', 'pending', null),
        ];

        $selected = $budget->select($candidates, $this->now);

        $this->assertSame(['NEW'], array_map(static fn (BackfillCandidate $c): string => $c->symbol, $selected));
    }

    public function test_the_longest_waiting_symbol_is_picked_first_among_attempted_ones(): void
    {
        $budget = new BackfillBudget(symbolsPerRun: 1, retryAfterHours: 0);

        $candidates = [
            new BackfillCandidate(1, 'RECENT', 'active', $this->now->copy()->subHours(1)),
            new BackfillCandidate(2, 'STALE', 'active', $this->now->copy()->subHours(100)),
        ];

        $selected = $budget->select($candidates, $this->now);

        $this->assertSame(['STALE'], array_map(static fn (BackfillCandidate $c): string => $c->symbol, $selected));
    }

    public function test_a_symbol_still_inside_its_retry_cooldown_is_skipped(): void
    {
        $budget = new BackfillBudget(symbolsPerRun: 10, retryAfterHours: 20);

        $candidates = [
            // Attempted 1 hour ago — inside the 20-hour cooldown.
            new BackfillCandidate(1, 'TOO_SOON', 'active', $this->now->copy()->subHour()),
            // Attempted 25 hours ago — outside the cooldown.
            new BackfillCandidate(2, 'READY', 'active', $this->now->copy()->subHours(25)),
        ];

        $selected = $budget->select($candidates, $this->now);

        $this->assertSame(['READY'], array_map(static fn (BackfillCandidate $c): string => $c->symbol, $selected));
    }

    public function test_a_symbol_exactly_at_the_cooldown_boundary_is_eligible(): void
    {
        $budget = new BackfillBudget(symbolsPerRun: 10, retryAfterHours: 20);

        $candidates = [
            new BackfillCandidate(1, 'BOUNDARY', 'active', $this->now->copy()->subHours(20)),
        ];

        $selected = $budget->select($candidates, $this->now);

        $this->assertCount(1, $selected);
    }

    public function test_estimated_runs_to_clear_rounds_up(): void
    {
        $budget = new BackfillBudget(symbolsPerRun: 15, retryAfterHours: 20);

        $this->assertSame(22, $budget->estimatedRunsToClear(322));
        $this->assertSame(1, $budget->estimatedRunsToClear(1));
        $this->assertSame(0, $budget->estimatedRunsToClear(0));
    }

    public function test_estimated_runs_to_clear_never_divides_by_zero(): void
    {
        $budget = new BackfillBudget(symbolsPerRun: 0, retryAfterHours: 20);

        $this->assertSame(5, $budget->estimatedRunsToClear(5));
    }
}
