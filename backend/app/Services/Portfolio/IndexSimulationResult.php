<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

/**
 * Result of replaying a portfolio's real cash flows as index-unit purchases
 * and redemptions — the money-weighted "what if I'd bought the index instead"
 * answer, alongside `PerformanceService`'s fixed-deposit comparison.
 *
 * `value` is null, never zero, whenever the index has no level to mark the
 * end date at — the same rule `PortfolioTimeSeries::valueOn()` applies to a
 * portfolio position with no price.
 */
final class IndexSimulationResult
{
    /**
     * @param  list<string>  $uncoveredDates  Y-m-d flow dates the index series had no level for on or before — excluded from the simulation rather than priced at zero.
     */
    public function __construct(
        public readonly ?float $value,
        public readonly float $unitsHeld,
        public readonly bool $complete,
        public readonly array $uncoveredDates,
    ) {}

    public function known(): bool
    {
        return $this->value !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'units_held' => round($this->unitsHeld, 6),
            'complete' => $this->complete,
            'uncovered_dates' => $this->uncoveredDates,
        ];
    }
}
