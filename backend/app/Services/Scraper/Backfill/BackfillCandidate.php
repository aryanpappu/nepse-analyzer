<?php

declare(strict_types=1);

namespace App\Services\Scraper\Backfill;

use Illuminate\Support\Carbon;

/**
 * One symbol eligible in principle for a backfill attempt this run.
 *
 * A plain value object rather than the Eloquent model itself, so the budgeting
 * logic that picks between candidates (BackfillBudget) can be unit tested with
 * arrays of these instead of touching the database.
 */
final readonly class BackfillCandidate
{
    public function __construct(
        public int $stockId,
        public string $symbol,
        public string $status,
        public ?Carbon $lastAttemptedAt = null,
    ) {}
}
