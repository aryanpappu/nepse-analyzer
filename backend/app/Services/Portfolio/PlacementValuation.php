<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

/**
 * What a portfolio's cash placements were worth on one date, split by whether
 * each one may legitimately be added to net worth.
 *
 * The split is the whole point. Opening a placement does not have to debit a
 * cash account — a purely informational record leaves the principal sitting in
 * `bank_balance` — so `netWorthContribution` counts *only* placements whose
 * principal demonstrably left the cash ledger. `unsettledValue` is reported
 * alongside it, clearly labelled as already counted as idle cash, rather than
 * silently dropped or silently added.
 *
 * Produced by {@see PlacementValuator}, which never touches Eloquent.
 */
final class PlacementValuation
{
    /**
     * @param  list<array<string, mixed>>  $placements  Per-placement detail, oldest first.
     * @param  list<array{label: string, reason: string}>  $excluded  Named, never silently dropped.
     */
    public function __construct(
        public readonly string $asOf,
        public readonly int $count,
        /** Settled, still-open, interest-bearing placements: safe to add to net worth. */
        public readonly float $netWorthContribution,
        public readonly int $settledCount,
        /** Open but never debited from cash — that money is already in `bank_balance`. */
        public readonly float $unsettledValue,
        public readonly int $unsettledCount,
        /** Interest accrued to $asOf on placements still open. Income, never added to value. */
        public readonly float $accruedInterestOpen,
        /** Interest accrued up to closure on placements already closed. */
        public readonly float $accruedInterestClosed,
        public readonly float $principalPlaced,
        public readonly array $placements = [],
        public readonly array $excluded = [],
    ) {}

    /** Total interest these placements have earned, open and closed. */
    public function totalInterest(): float
    {
        return round($this->accruedInterestOpen + $this->accruedInterestClosed, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'as_of' => $this->asOf,
            'count' => $this->count,
            'net_worth_contribution' => round($this->netWorthContribution, 2),
            'settled_count' => $this->settledCount,
            'unsettled_value' => round($this->unsettledValue, 2),
            'unsettled_count' => $this->unsettledCount,
            'accrued_interest_open' => round($this->accruedInterestOpen, 2),
            'accrued_interest_closed' => round($this->accruedInterestClosed, 2),
            'total_interest' => $this->totalInterest(),
            'principal_placed' => round($this->principalPlaced, 2),
            'placements' => $this->placements,
            'excluded' => $this->excluded,
            'note' => $this->note(),
        ];
    }

    /**
     * Plain-English statement of exactly what is and is not in net worth, so a
     * page can never imply that an unsettled placement was counted.
     */
    public function note(): ?string
    {
        if ($this->count === 0) {
            return null;
        }

        if ($this->unsettledCount === 0) {
            return 'Placement principal is counted in net worth; it was debited from a cash account when each '
                .'placement was opened, so it is not also counted as idle cash. Interest is reported separately '
                .'and is not added to the placement value.';
        }

        return sprintf(
            '%d placement(s) worth NPR %s were recorded without a cash settlement: nothing was debited from '
            .'cash_in_hand or bank_balance, so that money is already counted as idle cash and is deliberately '
            .'NOT added to net worth again. Settle them (open with settle_cash) to have them counted as '
            .'placements instead.',
            $this->unsettledCount,
            number_format($this->unsettledValue, 2),
        );
    }
}
