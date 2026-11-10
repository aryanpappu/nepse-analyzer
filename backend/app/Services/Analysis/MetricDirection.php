<?php

declare(strict_types=1);

namespace App\Services\Analysis;

/**
 * Which way is "better" for a metric, when two scrips are compared.
 *
 * Several of these are genuinely contested and the choice is stated in
 * MetricCatalog rather than left implicit, because a head-to-head verdict is
 * only meaningful if the reader can see the convention that produced it.
 */
enum MetricDirection: string
{
    case Higher = 'higher';
    case Lower = 'lower';

    /**
     * No defensible ordering — reported for context, never scored.
     */
    case Neutral = 'neutral';

    /**
     * Compare two values, returning >0 when $a is better, <0 when $b is.
     */
    public function compare(float $a, float $b): int
    {
        return match ($this) {
            self::Higher => $a <=> $b,
            self::Lower => $b <=> $a,
            self::Neutral => 0,
        };
    }
}
