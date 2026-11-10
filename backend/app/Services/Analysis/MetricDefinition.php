<?php

declare(strict_types=1);

namespace App\Services\Analysis;

/**
 * One comparable metric: what it is called, how to read it, and which way is
 * better. See {@see MetricCatalog} for the registry.
 */
final readonly class MetricDefinition
{
    public function __construct(
        public string $key,
        public string $label,
        public MetricUnit $unit,
        public MetricDirection $better,
        public string $rationale,
    ) {}

    public function isScored(): bool
    {
        return $this->better !== MetricDirection::Neutral;
    }

    public function format(?float $value): string
    {
        return $this->unit->format($value);
    }
}
