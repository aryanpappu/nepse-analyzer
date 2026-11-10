<?php

declare(strict_types=1);

namespace App\Enums;

enum RiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public static function fromLoose(?string $value): self
    {
        $normalized = strtolower(trim((string) $value));

        return match (true) {
            str_contains($normalized, 'low') => self::Low,
            str_contains($normalized, 'high'),
            str_contains($normalized, 'very high') => self::High,
            default => self::Medium,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
