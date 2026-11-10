<?php

declare(strict_types=1);

namespace App\Enums;

enum TimeHorizon: string
{
    case ShortTerm = 'short_term';
    case MediumTerm = 'medium_term';
    case LongTerm = 'long_term';

    public function label(): string
    {
        return match ($this) {
            self::ShortTerm => 'Short term (days to weeks)',
            self::MediumTerm => 'Medium term (1-6 months)',
            self::LongTerm => 'Long term (6+ months)',
        };
    }

    public static function fromLoose(?string $value): self
    {
        $normalized = strtolower(str_replace([' ', '-'], '_', trim((string) $value)));

        return match (true) {
            str_contains($normalized, 'short') => self::ShortTerm,
            str_contains($normalized, 'long') => self::LongTerm,
            default => self::MediumTerm,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
