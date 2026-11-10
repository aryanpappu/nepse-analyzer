<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What to do about a scrip you already hold.
 *
 * This is the half the app was missing: a verdict tells you whether to buy,
 * but once you own something at a known average cost the question changes to
 * add / hold / trim / get out, and the answer depends on your cost base as
 * much as on the chart.
 */
enum PositionAction: string
{
    /** Position is under target size and the setup is still valid — buy more. */
    case Accumulate = 'accumulate';

    /** Right idea, wrong price. Wait for the named dip before adding. */
    case AddOnDip = 'add_on_dip';

    /** Thesis intact, position sized. Do nothing. */
    case Hold = 'hold';

    /** Take some off: target reached, or the weight has grown too large. */
    case Trim = 'trim';

    /** Thesis broken or the profile's hold window has expired. Close it. */
    case Exit = 'exit';

    /** Price has breached the stop. The decision was made in advance. */
    case StopLoss = 'stop_loss';

    public function label(): string
    {
        return match ($this) {
            self::Accumulate => 'Accumulate',
            self::AddOnDip => 'Add on dip',
            self::Hold => 'Hold',
            self::Trim => 'Trim',
            self::Exit => 'Exit',
            self::StopLoss => 'Stop loss hit',
        };
    }

    /**
     * Ordering for the action list — the things that need a decision today
     * float to the top, the do-nothings sink.
     */
    public function urgency(): int
    {
        return match ($this) {
            self::StopLoss => 5,
            self::Exit => 4,
            self::Trim => 3,
            self::Accumulate => 2,
            self::AddOnDip => 1,
            self::Hold => 0,
        };
    }

    public function isSell(): bool
    {
        return $this === self::Trim || $this === self::Exit || $this === self::StopLoss;
    }
}
