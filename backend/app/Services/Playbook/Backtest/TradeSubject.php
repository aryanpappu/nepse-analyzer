<?php

declare(strict_types=1);

namespace App\Services\Playbook\Backtest;

use App\Enums\InvestorProfile;
use App\Enums\VerdictGrade;

/**
 * Who and what a simulated plan belongs to.
 *
 * Kept off {@see TradePlan} so the plan stays purely geometric and the
 * simulator can be tested on levels alone, while the report still knows which
 * grade and profile to file each result under.
 */
final class TradeSubject
{
    public function __construct(
        public readonly string $symbol,
        public readonly InvestorProfile $profile,
        public readonly VerdictGrade $grade,
        public readonly float $score,
    ) {}
}
