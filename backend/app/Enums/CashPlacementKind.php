<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What kind of thing a cash placement record represents.
 *
 * "Collateral" is ambiguous in the Nepali market: it can mean money parked in
 * a fixed deposit (often pledged as collateral for a margin loan), or shares
 * pledged against a margin loan. This discriminator exists so the second
 * meaning can be added later without a migration — today only FixedDeposit
 * accrues interest; PledgedShares is a reserved placeholder.
 */
enum CashPlacementKind: string
{
    /** Money placed in a bank fixed deposit — interest-bearing. */
    case FixedDeposit = 'fixed_deposit';

    /**
     * Reserved for later: shares pledged as collateral against a margin loan.
     * Not interest-bearing in the sense this feature computes, and nothing
     * here reads or writes that meaning yet — the column simply already
     * accepts the value so no schema change is needed when it is built out.
     */
    case PledgedShares = 'pledged_shares';

    /** Only a fixed deposit earns interest under this feature's maths. */
    public function isInterestBearing(): bool
    {
        return $this === self::FixedDeposit;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
