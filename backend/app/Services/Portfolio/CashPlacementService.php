<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Enums\CashPlacementKind;
use App\Enums\CashPlacementStatus;
use App\Enums\CompoundingBasis;
use App\Enums\PayoutHandling;
use App\Models\CashPlacement;
use App\Models\CashTransaction;
use App\Models\Portfolio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Persistence and reporting for cash placements. The interest maths itself
 * lives in {@see InterestAccrualCalculator}, kept pure and Eloquent-free so it
 * can be unit tested without a database; this service is the seam that feeds
 * it a placement's stored terms and turns the result into API/CLI shapes.
 *
 * Opening and closing a placement can optionally move the matching cash
 * through {@see CashLedger}, exactly the way `HoldingController` offers
 * `settle_cash` + `cash_account` alongside a trade. It is optional because a
 * placement recorded before this existed is purely informational, and it is
 * explicit because settling changes what net worth means: a settled
 * placement's principal has left `bank_balance` and is therefore counted as a
 * placement, while an unsettled one is still idle cash and must not be counted
 * twice. {@see PlacementValuator} enforces that side of the bargain.
 */
final class CashPlacementService
{
    /** Cash account a settlement defaults to when the caller names none. */
    private const DEFAULT_SETTLEMENT_ACCOUNT = CashTransaction::ACCOUNT_BANK;

    public function __construct(
        private readonly InterestAccrualCalculator $calculator,
        private readonly CashLedger $ledger = new CashLedger,
        private readonly CashPlacementSettlement $settlement = new CashPlacementSettlement,
    ) {}

    /**
     * Record a placement, optionally debiting its principal from a cash
     * account so the account reconciles without a second manual entry.
     *
     * `settle_cash` (bool) and `cash_account` (`cash`|`bank`) are consumed
     * here and are not placement columns. The debit is dated the placement's
     * own `start_date` — that is the day the money actually left the account
     * — and is written inside the same transaction as the placement, so an
     * overdraw (which `CashLedger::record()` refuses) rolls the placement
     * back rather than leaving a placement whose money never moved.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(Portfolio $portfolio, array $attributes): CashPlacement
    {
        $settle = ($attributes['settle_cash'] ?? false) === true;
        $account = $this->settlementAccount($attributes);

        return DB::transaction(function () use ($portfolio, $attributes, $settle, $account): CashPlacement {
            $placement = new CashPlacement($this->defaultsForCreate($attributes));
            $placement->portfolio()->associate($portfolio);
            $placement->save();

            if ($settle) {
                $principal = round((float) $placement->principal, 2);

                if ($principal >= 0.01) {
                    $this->ledger->record(
                        $portfolio,
                        $account,
                        -$principal,
                        CashPlacementSettlement::TYPE_OPEN,
                        CashPlacementSettlement::referenceFor((int) $placement->id),
                        sprintf(
                            'Opened %s placement of NPR %s at %s%%',
                            $placement->kind->value,
                            number_format($principal, 2),
                            (float) $placement->interest_rate,
                        ),
                        $placement->start_date->copy(),
                    );
                }
            }

            return $placement;
        });
    }

    /**
     * Close a placement and, optionally, deposit the money back into a cash
     * account: principal as one row, realised interest as a second.
     *
     * Two rows rather than one lump because they answer different questions —
     * and because interest handling differs by payout. A **reinvested**
     * placement never disbursed anything, so its interest comes back with the
     * principal and `include_interest` defaults to true. A **paid-out**
     * placement disbursed interest during the term (each of those payments is
     * an ordinary deposit in the cash ledger already), so only the principal
     * returns and `include_interest` defaults to false — adding it would pay
     * the same interest twice.
     *
     * Settlement defaults to mirroring the opening: a placement whose
     * principal was debited on open is credited back on close, and a purely
     * informational one stays informational.
     *
     * @param  array{closed_on?: string|Carbon|null, settle_cash?: bool, cash_account?: string, include_interest?: bool, notes?: ?string}  $attributes
     */
    public function withdraw(CashPlacement $placement, array $attributes = []): CashPlacement
    {
        $portfolio = $placement->portfolio;
        $closedOn = $this->closureDate($placement, $attributes['closed_on'] ?? null);
        $settledOnOpen = isset($this->settlement->settledIds($portfolio, [(int) $placement->id])[(int) $placement->id]);
        $settle = ($attributes['settle_cash'] ?? $settledOnOpen) === true;
        $account = $this->settlementAccount($attributes);
        $includeInterest = ($attributes['include_interest'] ?? ($placement->payout === PayoutHandling::Reinvested)) === true;

        return DB::transaction(function () use ($placement, $portfolio, $closedOn, $settle, $account, $includeInterest, $attributes): CashPlacement {
            $placement->closed_on = $closedOn;
            $placement->status = CashPlacementStatus::Withdrawn;

            if (array_key_exists('notes', $attributes)) {
                $placement->notes = $attributes['notes'];
            }

            $placement->save();

            if (! $settle) {
                return $placement;
            }

            $accrual = $this->accrualFor($placement, $closedOn);
            $principal = round((float) $placement->principal, 2);

            if ($principal >= 0.01) {
                $this->ledger->record(
                    $portfolio,
                    $account,
                    $principal,
                    CashPlacementSettlement::TYPE_CLOSE,
                    CashPlacementSettlement::referenceFor((int) $placement->id),
                    sprintf('Withdrew placement principal of NPR %s', number_format($principal, 2)),
                    $closedOn->copy(),
                );
            }

            $interest = round($accrual->accruedInterest, 2);

            if ($includeInterest && $interest >= 0.01) {
                $this->ledger->record(
                    $portfolio,
                    $account,
                    $interest,
                    CashPlacementSettlement::TYPE_INTEREST,
                    CashPlacementSettlement::referenceFor((int) $placement->id),
                    sprintf('Placement interest of NPR %s over %d days', number_format($interest, 2), $accrual->daysAccrued),
                    $closedOn->copy(),
                );
            }

            return $placement;
        });
    }

    /**
     * All-or-nothing bulk create: every row has already passed validation as
     * a set (the request's FormRequest rejects the whole payload if any row
     * is invalid before this runs), and the transaction guarantees a DB-level
     * failure on one row rolls every row back rather than leaving a partial
     * batch behind.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, CashPlacement>
     */
    public function createMany(Portfolio $portfolio, array $rows): Collection
    {
        return DB::transaction(fn (): Collection => collect($rows)
            ->map(fn (array $row): CashPlacement => $this->create($portfolio, $row))
            ->values());
    }

    /**
     * Edit a placement's terms. Deliberately does **not** re-settle cash: it
     * changes what the placement says, not where the money is. Amending the
     * `principal` of a placement that was opened with a settlement therefore
     * leaves the original `placement_debit` row at the old amount — the same
     * known gap as amending a `trade_debit` row without its paired
     * `PortfolioTransaction`. Correct it by amending that ledger row through
     * `CashLedger::amend()`, which replays the account rather than patching it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(CashPlacement $placement, array $attributes): CashPlacement
    {
        $placement->fill($this->fieldsForUpdate($attributes));

        $start = $placement->start_date;
        $maturity = $placement->maturity_date;
        $closed = $placement->closed_on;

        if ($start !== null && $maturity !== null && $maturity->lte($start)) {
            throw new InvalidArgumentException('Maturity date must be after the start date.');
        }

        if ($start !== null && $closed !== null && $closed->lt($start)) {
            throw new InvalidArgumentException('Closed date cannot be before the start date.');
        }

        $placement->save();

        return $placement;
    }

    /**
     * @return Collection<int, CashPlacement>
     */
    public function forPortfolio(Portfolio $portfolio): Collection
    {
        return CashPlacement::query()
            ->where('portfolio_id', $portfolio->id)
            ->orderByDesc('start_date')
            ->get();
    }

    /**
     * Interest earned to $asOf (default today), capped at maturity/closure.
     */
    public function accrualFor(CashPlacement $placement, ?Carbon $asOf = null): AccrualResult
    {
        $asOf ??= Carbon::today();

        return $this->calculator->accrue(
            (float) $placement->principal,
            (float) $placement->interest_rate,
            $placement->start_date->copy(),
            $asOf->copy(),
            $placement->compounding_basis,
            $placement->payout,
            $placement->maturity_date?->copy(),
            $placement->closed_on?->copy(),
        );
    }

    /**
     * Maturity awareness across a set of placements: what has matured, what
     * matures soon, total principal still placed, total interest earned to
     * date, and the principal-weighted average rate of live capital.
     *
     * Maturity is judged by date first, not stored status — a placement
     * whose maturity_date has passed is matured whether or not anyone has
     * gone back to flip its status, so the count stays honest without manual
     * upkeep. Total principal excludes withdrawn placements (the capital has
     * left this record and is back in the cash ledger); it includes matured
     * and broken-early ones, since the money is still nominally "placed"
     * until an explicit withdrawal is recorded. The weighted average rate is
     * scoped to placements that are both `active` and interest-bearing —
     * a matured or broken placement is no longer earning at its stated rate,
     * so folding it in would misstate what the live book is currently
     * earning.
     *
     * @param  Collection<int, CashPlacement>  $placements
     * @return array{as_of: string, count: int, total_principal: float, total_accrued_interest: float, matured_count: int, maturing_soon_count: int, maturing_soon_within_days: int, weighted_average_rate: ?float}
     */
    public function summarize(Collection $placements, ?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $maturingSoonDays = (int) config('nepse.cash_placements.maturing_soon_days', 30);
        $windowEnd = $asOf->copy()->addDays($maturingSoonDays);

        $principal = 0.0;
        $accrued = 0.0;
        $rateWeighted = 0.0;
        $rateBase = 0.0;
        $matured = 0;
        $maturingSoon = 0;

        foreach ($placements as $placement) {
            $accrued += $this->accrualFor($placement, $asOf)->accruedInterest;

            if ($placement->status !== CashPlacementStatus::Withdrawn) {
                $principal += (float) $placement->principal;
            }

            $maturity = $placement->maturity_date;
            $maturedByStatus = in_array(
                $placement->status,
                [CashPlacementStatus::Matured, CashPlacementStatus::Withdrawn, CashPlacementStatus::BrokenEarly],
                true,
            );
            $maturedByDate = $maturity !== null && $maturity->lte($asOf);

            if ($maturedByStatus || $maturedByDate) {
                $matured++;
            } elseif ($maturity !== null && $maturity->gt($asOf) && $maturity->lte($windowEnd)) {
                $maturingSoon++;
            }

            if ($placement->status === CashPlacementStatus::Active && $placement->kind->isInterestBearing()) {
                $rateWeighted += (float) $placement->principal * (float) $placement->interest_rate;
                $rateBase += (float) $placement->principal;
            }
        }

        return [
            'as_of' => $asOf->toDateString(),
            'count' => $placements->count(),
            'total_principal' => round($principal, 2),
            'total_accrued_interest' => round($accrued, 2),
            'matured_count' => $matured,
            'maturing_soon_count' => $maturingSoon,
            'maturing_soon_within_days' => $maturingSoonDays,
            'weighted_average_rate' => $rateBase > 0.0 ? round($rateWeighted / $rateBase, 3) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function settlementAccount(array $attributes): string
    {
        $account = (string) ($attributes['cash_account'] ?? self::DEFAULT_SETTLEMENT_ACCOUNT);

        if (! in_array($account, CashTransaction::accounts(), true)) {
            throw new InvalidArgumentException("Unknown cash account [{$account}].");
        }

        return $account;
    }

    /**
     * Closure date, defaulting to today and never before the placement
     * started — a closure before the start would make the accrual window
     * negative and the interest figure meaningless.
     */
    private function closureDate(CashPlacement $placement, string|Carbon|null $given): Carbon
    {
        $closedOn = $given === null
            ? Carbon::today()
            : ($given instanceof Carbon ? $given->copy() : Carbon::parse($given));

        $closedOn = $closedOn->startOfDay();

        if ($closedOn->lt($placement->start_date)) {
            throw new InvalidArgumentException('Closed date cannot be before the start date.');
        }

        return $closedOn;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function defaultsForCreate(array $attributes): array
    {
        return [
            'kind' => $attributes['kind']
                ?? config('nepse.cash_placements.default_kind', CashPlacementKind::FixedDeposit->value),
            'institution' => $attributes['institution'] ?? null,
            'reference' => $attributes['reference'] ?? null,
            'principal' => (float) $attributes['principal'],
            // Omitted entirely, this reuses the existing FD benchmark rate
            // (nepse.benchmark.fixed_deposit_rate / NEPSE_FD_RATE) rather than
            // inventing a second "default rate" concept — the same number the
            // performance report already compares a portfolio against.
            'interest_rate' => isset($attributes['interest_rate'])
                ? (float) $attributes['interest_rate']
                : (float) config('nepse.benchmark.fixed_deposit_rate', 8.0),
            'start_date' => $attributes['start_date'],
            'maturity_date' => $attributes['maturity_date'] ?? null,
            'closed_on' => $attributes['closed_on'] ?? null,
            'compounding_basis' => $attributes['compounding_basis']
                ?? config('nepse.cash_placements.default_compounding_basis', CompoundingBasis::Simple->value),
            'payout' => $attributes['payout']
                ?? config('nepse.cash_placements.default_payout', PayoutHandling::PaidOut->value),
            'status' => $attributes['status']
                ?? config('nepse.cash_placements.default_status', CashPlacementStatus::Active->value),
            'notes' => $attributes['notes'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function fieldsForUpdate(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip([
            'kind', 'institution', 'reference', 'principal', 'interest_rate',
            'start_date', 'maturity_date', 'closed_on', 'compounding_basis',
            'payout', 'status', 'notes',
        ]));
    }
}
