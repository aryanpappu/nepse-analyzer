<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether earned interest is paid out or left to compound.
 *
 * A Nepali FD quoted as "a simple annual rate with quarterly payout" pays
 * each quarter's interest out rather than adding it back to the principal —
 * the balance that keeps earning never grows, so the maths stays simple
 * regardless of how the payout is scheduled. `Reinvested` is the cumulative
 * FD case: interest is added back at each `CompoundingBasis` period and
 * itself starts earning, which is true compounding.
 */
enum PayoutHandling: string
{
    case PaidOut = 'paid_out';
    case Reinvested = 'reinvested';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
