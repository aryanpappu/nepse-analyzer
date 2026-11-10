<?php

declare(strict_types=1);

namespace App\Enums;

enum CashPlacementStatus: string
{
    case Active = 'active';
    case Matured = 'matured';
    case Withdrawn = 'withdrawn';
    /** Closed before maturity, typically at a reduced/forfeited rate. */
    case BrokenEarly = 'broken_early';

    /** Whether the placement still holds capital that has not been taken out. */
    public function isOpen(): bool
    {
        return $this !== self::Withdrawn;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
