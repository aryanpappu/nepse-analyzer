<?php

declare(strict_types=1);

namespace App\Services\Playbook\Backtest;

use App\Enums\InvestorProfile;
use App\Services\Playbook\PlaybookVerdict;

/**
 * The engine's own trade geometry, reduced to what a simulator needs.
 *
 * Nothing here is invented: the band, the stop and the target come straight off
 * the {@see PlaybookVerdict}, and the two windows come straight off the
 * {@see InvestorProfile}. That is the point — the backtest has to measure the
 * plan the engine actually emits, not "buy whatever was graded and hold".
 *
 * A verdict with an incomplete geometry produces no plan (null), rather than a
 * plan with a guessed level in it.
 */
final class TradePlan
{
    public function __construct(
        public readonly string $signalDate,
        public readonly float $entryLow,
        public readonly float $entryHigh,
        public readonly float $stopLoss,
        public readonly float $target,
        /** Sessions after the signal in which the band must be traded, or the plan lapses. */
        public readonly int $entryWindowSessions,
        /** The profile's hold ceiling, in sessions, counted from the fill. */
        public readonly int $maxHoldSessions,
    ) {}

    /**
     * @param  'target_1'|'target_2'  $exitTarget
     */
    public static function fromVerdict(
        PlaybookVerdict $verdict,
        string $signalDate,
        int $entryWindowSessions,
        ?int $maxHoldSessionsOverride = null,
        string $exitTarget = 'target_1',
    ): ?self {
        $target = $exitTarget === 'target_2' ? $verdict->target2 : $verdict->target1;

        if ($verdict->entryLow === null || $verdict->entryHigh === null
            || $verdict->stopLoss === null || $target === null) {
            return null;
        }

        if ($verdict->entryHigh <= 0.0 || $verdict->entryLow <= 0.0 || $target <= $verdict->entryHigh) {
            return null;
        }

        return new self(
            signalDate: $signalDate,
            entryLow: $verdict->entryLow,
            entryHigh: $verdict->entryHigh,
            stopLoss: $verdict->stopLoss,
            target: $target,
            entryWindowSessions: max(1, $entryWindowSessions),
            maxHoldSessions: max(1, $maxHoldSessionsOverride ?? $verdict->profile->holdSessions()[1]),
        );
    }
}
