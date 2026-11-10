<?php

declare(strict_types=1);

namespace App\Enums;

enum TransactionType: string
{
    case Buy = 'buy';
    case Sell = 'sell';
    /** Bonus shares increase quantity at zero cost, diluting average cost. */
    case Bonus = 'bonus';
    /** Cash dividend — no quantity change, treated as realised income. */
    case Dividend = 'dividend';
    /** Rights issue — new shares bought at the (usually par) offer price. */
    case Rights = 'rights';

    public function increasesQuantity(): bool
    {
        return in_array($this, [self::Buy, self::Bonus, self::Rights], true);
    }

    public function decreasesQuantity(): bool
    {
        return $this === self::Sell;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
