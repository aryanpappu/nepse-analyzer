<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How often interest is credited/compounded, when it compounds at all.
 *
 * `Simple` means the annual rate is applied once, pro-rated by exact days,
 * with no periodic compounding step — the placement never re-bases on its
 * own earned interest. The others name a compounding period; whether that
 * period's interest is actually added back to the accruing balance is a
 * separate question, answered by `PayoutHandling`.
 */
enum CompoundingBasis: string
{
    case Simple = 'simple';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SemiAnnually = 'semi_annually';
    case Annually = 'annually';

    /** Calendar months per compounding period, or null for Simple (no period). */
    public function monthsPerPeriod(): ?int
    {
        return match ($this) {
            self::Simple => null,
            self::Monthly => 1,
            self::Quarterly => 3,
            self::SemiAnnually => 6,
            self::Annually => 12,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
