<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

/**
 * The tax position of one realised round trip.
 *
 * Produced by {@see CapitalGainsTaxCalculator} from a {@see ClosedTrade}, so
 * the gain and the FIFO acquisition date are the same ones the performance
 * report shows — a tax figure that disagreed with the realised gain beside it
 * would be worse than no tax figure.
 *
 * `estimatedTax` and `netGain` are null, never 0.0, when the disposal cannot
 * be rated. Zero is a real answer (a loss makes no gain to tax); unknown is
 * not, and the two must not print the same.
 */
final class CapitalGainsTaxAssessment
{
    public const EXCLUDED_NO_ACQUISITION_DATE = 'acquisition_date_unknown';

    public function __construct(
        public readonly string $symbol,
        public readonly string $closedOn,
        public readonly ?string $openedOn,
        public readonly ?int $daysHeld,
        public readonly float $quantity,
        public readonly float $costBasis,
        public readonly float $proceeds,
        /** Proceeds net of sell-side fees, less the fee-inclusive cost basis. May be negative. */
        public readonly float $grossGain,
        public readonly ?string $rateBand,
        public readonly ?float $ratePercent,
        public readonly string $rateReason,
        public readonly ?float $estimatedTax,
        public readonly ?float $netGain,
        public readonly bool $isAssessable,
        public readonly ?string $exclusionReason = null,
    ) {}

    public function isGain(): bool
    {
        return $this->grossGain > 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'opened_on' => $this->openedOn,
            'closed_on' => $this->closedOn,
            'days_held' => $this->daysHeld,
            'quantity' => $this->quantity,
            'cost_basis' => $this->costBasis,
            'proceeds' => $this->proceeds,
            'gross_gain' => $this->grossGain,
            'rate_band' => $this->rateBand,
            'rate_percent' => $this->ratePercent,
            'rate_reason' => $this->rateReason,
            'estimated_tax' => $this->estimatedTax,
            'net_gain' => $this->netGain,
            'is_assessable' => $this->isAssessable,
            'exclusion_reason' => $this->exclusionReason,
        ];
    }
}
