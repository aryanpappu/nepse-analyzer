<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Enums\CashPlacementKind;
use App\Enums\CashPlacementStatus;
use App\Enums\CompoundingBasis;
use App\Enums\PayoutHandling;
use Illuminate\Support\Carbon;

/**
 * What a set of cash placements is worth on a given date — pure, no Eloquent,
 * no clock, no config. Every input is a plain value, exactly like
 * {@see InterestAccrualCalculator} (which this delegates the interest maths
 * to) and {@see IndexReturnCalculator}, so the rules below are provable in a
 * unit test without a database.
 *
 * Three rules do all the work:
 *
 * 1. **A placement is worth `principal_at_end`, never `principal + interest`.**
 *    For a reinvested placement `principal_at_end` already contains the
 *    compounded interest. For a paid-out one it equals the principal, because
 *    that interest was *disbursed* during the term — it is in the cash ledger
 *    (or in the investor's pocket), and adding it here would count it twice.
 *    Accrued interest is therefore reported separately as income and never
 *    folded into the value.
 *
 * 2. **Only a cash-settled placement counts toward net worth.** Opening a
 *    placement is not required to debit a cash account. If nothing was
 *    debited, the principal is still sitting in `bank_balance` and
 *    `Portfolio::investableCash()` already counts it; counting the placement
 *    as well would double the same rupees. Unsettled placements are reported
 *    with their value and a reason, never silently added and never silently
 *    dropped.
 *
 * 3. **A placement is worth zero outside its active window.** Before
 *    `start_date` the money had not been placed yet. On and after `closed_on`
 *    it has been taken back out — a withdrawal settles principal (and, for a
 *    reinvested placement, interest) back into cash, so continuing to value
 *    the placement would double-count from the other direction. Between
 *    maturity and withdrawal the money is still nominally placed, so it keeps
 *    its matured value, with accrual capped at maturity by the accrual
 *    calculator itself.
 */
final class PlacementValuator
{
    /** Not yet started: the as-of date is before the placement's start_date. */
    public const STATE_NOT_STARTED = 'not_started';

    /** Live and accruing. */
    public const STATE_ACTIVE = 'active';

    /** Past maturity but not yet withdrawn — still placed, no longer accruing. */
    public const STATE_MATURED = 'matured';

    /** Closed on or before the as-of date: the money is back in the cash ledger. */
    public const STATE_CLOSED = 'closed';

    /** Marked withdrawn with no closed_on, so the exit cannot be dated. */
    public const STATE_UNDATED_EXIT = 'undated_exit';

    /** Not a cash placement at all (pledged shares) — valued with the holdings. */
    public const STATE_NOT_CASH = 'not_cash';

    public function __construct(
        private readonly InterestAccrualCalculator $accrual = new InterestAccrualCalculator,
    ) {}

    /**
     * @param  list<array{
     *     id: int|string,
     *     label?: ?string,
     *     kind: CashPlacementKind,
     *     status: CashPlacementStatus,
     *     principal: float,
     *     interest_rate: float,
     *     start_date: Carbon,
     *     maturity_date?: ?Carbon,
     *     closed_on?: ?Carbon,
     *     compounding_basis: CompoundingBasis,
     *     payout: PayoutHandling,
     *     cash_settled: bool,
     * }>  $placements
     */
    public function valueOn(array $placements, Carbon $asOf): PlacementValuation
    {
        $on = $asOf->copy()->startOfDay();

        $netWorth = 0.0;
        $settledCount = 0;
        $unsettledValue = 0.0;
        $unsettledCount = 0;
        $interestOpen = 0.0;
        $interestClosed = 0.0;
        $principalPlaced = 0.0;
        $rows = [];
        $excluded = [];

        foreach ($placements as $placement) {
            $row = $this->row($placement, $on);
            $rows[] = $row;

            if ($row['reason'] !== null) {
                $excluded[] = ['label' => $row['label'], 'reason' => $row['reason']];
            }

            $state = $row['state'];
            $value = $row['value'] ?? 0.0;
            $interest = $row['accrued_interest'] ?? 0.0;

            if ($state === self::STATE_CLOSED) {
                $interestClosed += $interest;

                continue;
            }

            if ($state !== self::STATE_ACTIVE && $state !== self::STATE_MATURED) {
                // Not started, undated exit, or not a cash placement: no value
                // and no income to claim on this date.
                continue;
            }

            $interestOpen += $interest;
            $principalPlaced += (float) $placement['principal'];

            if ($row['counts_toward_net_worth'] === true) {
                $netWorth += $value;
                $settledCount++;
            } else {
                $unsettledValue += $value;
                $unsettledCount++;
            }
        }

        return new PlacementValuation(
            asOf: $on->toDateString(),
            count: count($placements),
            netWorthContribution: round($netWorth, 2),
            settledCount: $settledCount,
            unsettledValue: round($unsettledValue, 2),
            unsettledCount: $unsettledCount,
            accruedInterestOpen: round($interestOpen, 2),
            accruedInterestClosed: round($interestClosed, 2),
            principalPlaced: round($principalPlaced, 2),
            placements: $rows,
            excluded: $excluded,
        );
    }

    /**
     * @param  array<string, mixed>  $placement
     * @return array<string, mixed>
     */
    private function row(array $placement, Carbon $on): array
    {
        $start = ($placement['start_date'] instanceof Carbon ? $placement['start_date'] : Carbon::parse((string) $placement['start_date']))
            ->copy()->startOfDay();
        $maturity = $this->date($placement['maturity_date'] ?? null);
        $closed = $this->date($placement['closed_on'] ?? null);
        $kind = $placement['kind'];
        $status = $placement['status'];
        $settled = ($placement['cash_settled'] ?? false) === true;
        $given = trim((string) ($placement['label'] ?? ''));
        $label = $given !== '' ? $given : 'Placement #'.$placement['id'];

        $base = [
            'id' => $placement['id'],
            'label' => $label,
            'kind' => $kind->value,
            'status' => $status->value,
            'principal' => round((float) $placement['principal'], 2),
            'interest_rate' => (float) $placement['interest_rate'],
            'start_date' => $start->toDateString(),
            'maturity_date' => $maturity?->toDateString(),
            'closed_on' => $closed?->toDateString(),
            'cash_settled' => $settled,
        ];

        // Pledged shares are not money in a bank — the shares themselves are
        // already marked to market with the holdings, so valuing this record
        // as cash too would count the same asset twice.
        if (! $kind->isInterestBearing()) {
            return $base + [
                'state' => self::STATE_NOT_CASH,
                'value' => null,
                'accrued_interest' => null,
                'days_accrued' => 0,
                'is_capped_at_maturity' => false,
                'counts_toward_net_worth' => false,
                'reason' => sprintf(
                    'Kind "%s" is not an interest-bearing cash placement, so it is not valued as cash here.',
                    $kind->value,
                ),
            ];
        }

        if ($on->lt($start)) {
            return $base + [
                'state' => self::STATE_NOT_STARTED,
                'value' => 0.0,
                'accrued_interest' => 0.0,
                'days_accrued' => 0,
                'is_capped_at_maturity' => false,
                'counts_toward_net_worth' => false,
                'reason' => null,
            ];
        }

        // Withdrawn with no closure date: the money has left, but nothing says
        // when. Reporting it at value would overstate every past date and
        // reporting zero would understate them, so it is excluded and named.
        if ($status === CashPlacementStatus::Withdrawn && $closed === null) {
            return $base + [
                'state' => self::STATE_UNDATED_EXIT,
                'value' => null,
                'accrued_interest' => null,
                'days_accrued' => 0,
                'is_capped_at_maturity' => false,
                'counts_toward_net_worth' => false,
                'reason' => 'Marked withdrawn but has no closed_on date, so the exit cannot be dated and the '
                    .'placement is excluded from valuation rather than valued at a guess.',
            ];
        }

        $accrual = $this->accrual->accrue(
            (float) $placement['principal'],
            (float) $placement['interest_rate'],
            $start,
            $on,
            $placement['compounding_basis'],
            $placement['payout'],
            $maturity,
            $closed,
        );

        $detail = [
            // Worth `principal_at_end`, never principal plus paid-out
            // interest — see the class docblock, rule 1.
            'value' => round($accrual->principalAtEnd, 2),
            'accrued_interest' => $accrual->accruedInterest,
            'days_accrued' => $accrual->daysAccrued,
            'is_capped_at_maturity' => $accrual->isCappedAtMaturity,
        ];

        if ($closed !== null && $closed->lte($on)) {
            // Closed on or before this date: the withdrawal returned the money
            // to the cash ledger, so the placement itself is worth nothing
            // here. Its interest to closure is still reported as income.
            $detail['value'] = 0.0;

            return $base + $detail + [
                'state' => self::STATE_CLOSED,
                'counts_toward_net_worth' => false,
                'reason' => null,
            ];
        }

        $matured = $maturity !== null && $maturity->lte($on);

        return $base + $detail + [
            'state' => $matured ? self::STATE_MATURED : self::STATE_ACTIVE,
            'counts_toward_net_worth' => $settled,
            'reason' => $settled
                ? null
                : 'Opened without a cash settlement: no cash account was debited, so this principal is already '
                    .'counted as idle cash and is excluded from net worth to avoid counting it twice.',
        ];
    }

    private function date(mixed $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        return ($value instanceof Carbon ? $value : Carbon::parse((string) $value))->copy()->startOfDay();
    }
}
