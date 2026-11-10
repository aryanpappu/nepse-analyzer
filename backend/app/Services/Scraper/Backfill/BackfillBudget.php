<?php

declare(strict_types=1);

namespace App\Services\Scraper\Backfill;

use Illuminate\Support\Carbon;

/**
 * Picks which symbols a single drip run should spend its request budget on,
 * and reports how many runs the remaining backlog will need.
 *
 * These are free public sites, not a paid API we can burst against - a
 * scheduled run therefore touches a *few* symbols and stops, no matter how far
 * behind the deep-history target the market as a whole still is. This is the
 * pure arithmetic behind that: no DB, no HTTP, so it is testable with plain
 * arrays and `Carbon` instances.
 */
final class BackfillBudget
{
    public function __construct(
        private readonly int $symbolsPerRun,
        private readonly int $retryAfterHours,
    ) {}

    /**
     * @param  list<BackfillCandidate>  $candidates  Symbols eligible in
     *                                               principle (status
     *                                               pending or active).
     * @return list<BackfillCandidate> At most `symbolsPerRun` entries: never
     *                                 attempted first, then whoever was
     *                                 checked longest ago, skipping anything
     *                                 still inside its retry cooldown.
     */
    public function select(array $candidates, Carbon $now): array
    {
        $eligible = array_values(array_filter(
            $candidates,
            fn (BackfillCandidate $candidate): bool => $this->isEligible($candidate, $now),
        ));

        usort($eligible, static function (BackfillCandidate $a, BackfillCandidate $b): int {
            return match (true) {
                $a->lastAttemptedAt === null && $b->lastAttemptedAt === null => 0,
                $a->lastAttemptedAt === null => -1,
                $b->lastAttemptedAt === null => 1,
                default => $a->lastAttemptedAt <=> $b->lastAttemptedAt,
            };
        });

        return array_slice($eligible, 0, $this->symbolsPerRun);
    }

    /**
     * How many scheduled runs it will take to give every pending/active
     * symbol at least one attempt, at the current budget.
     */
    public function estimatedRunsToClear(int $pendingCount): int
    {
        if ($pendingCount <= 0) {
            return 0;
        }

        return (int) ceil($pendingCount / max(1, $this->symbolsPerRun));
    }

    private function isEligible(BackfillCandidate $candidate, Carbon $now): bool
    {
        if (! in_array($candidate->status, ['pending', 'active'], true)) {
            return false;
        }

        if ($candidate->lastAttemptedAt === null) {
            return true;
        }

        return $candidate->lastAttemptedAt->diffInHours($now) >= $this->retryAfterHours;
    }
}
