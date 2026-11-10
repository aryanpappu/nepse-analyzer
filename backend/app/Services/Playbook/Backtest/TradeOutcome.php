<?php

declare(strict_types=1);

namespace App\Services\Playbook\Backtest;

/**
 * How a simulated plan ended.
 *
 * The two unresolved cases carry the honesty of the whole exercise. A plan the
 * market never traded into is `NoFill` — not a flat trade, and not a trade at
 * all; counting it as 0% would dilute every average towards zero and make a
 * strategy that rarely fills look safe. A plan whose hold window extends past
 * the last bar in the database is `Unresolved` — the answer is unknown, and an
 * unknown is reported as such rather than marked to the last close, which
 * would quietly turn every open long-term position into a winner in a rising
 * market.
 */
enum TradeOutcome: string
{
    /** Filled, and the first target was reached. */
    case Target = 'target';

    /** Filled, and the stop was breached. */
    case Stop = 'stop';

    /** Filled, neither level reached inside the profile's hold ceiling. */
    case Expired = 'expired';

    /** Price never traded into the entry band inside the entry window. */
    case NoFill = 'no_fill';

    /** Not enough forward bars to answer either question. */
    case Unresolved = 'unresolved';

    public function isResolved(): bool
    {
        return $this === self::Target || $this === self::Stop || $this === self::Expired;
    }

    public function label(): string
    {
        return match ($this) {
            self::Target => 'Target hit',
            self::Stop => 'Stopped out',
            self::Expired => 'Hold window expired',
            self::NoFill => 'Never filled',
            self::Unresolved => 'Unresolved (data ended)',
        };
    }
}
