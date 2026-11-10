<?php

declare(strict_types=1);

namespace App\Enums;

enum SignalType: string
{
    case Buy = 'buy';
    case Sell = 'sell';
    case Hold = 'hold';

    public function label(): string
    {
        return match ($this) {
            self::Buy => 'Buy',
            self::Sell => 'Sell',
            self::Hold => 'Hold',
        };
    }

    /**
     * Models occasionally answer "STRONG BUY" or "accumulate" instead of the
     * three values we asked for. Rather than discard an otherwise good
     * analysis, map the common synonyms onto our vocabulary.
     */
    public static function fromLoose(?string $value): ?self
    {
        $normalized = strtolower(trim((string) $value));

        if ($normalized === '') {
            return null;
        }

        return match (true) {
            str_contains($normalized, 'buy'),
            str_contains($normalized, 'accumulate'),
            str_contains($normalized, 'bullish') => self::Buy,

            str_contains($normalized, 'sell'),
            str_contains($normalized, 'exit'),
            str_contains($normalized, 'reduce'),
            str_contains($normalized, 'bearish') => self::Sell,

            str_contains($normalized, 'hold'),
            str_contains($normalized, 'neutral'),
            str_contains($normalized, 'wait') => self::Hold,

            default => null,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
