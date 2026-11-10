<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Models\CashPlacement;
use App\Models\Portfolio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The Eloquent seam in front of {@see PlacementValuator}: it loads a
 * portfolio's placements, asks the cash ledger which of them were actually
 * settled, and hands the pure calculator plain values. Same division of labour
 * as {@see CashPlacementService} in front of
 * {@see InterestAccrualCalculator} — none of the rules live here.
 *
 * `valueOn()` answers any date, not just today, which is what
 * {@see PortfolioTimeSeries} needs: a placement's contribution to a past date
 * is its accrued value *as at that date*, not today's. Using today's figure
 * for a historical mark would credit last year's net worth with interest that
 * had not been earned yet.
 */
final class PlacementValuationService
{
    public function __construct(
        private readonly PlacementValuator $valuator = new PlacementValuator,
        private readonly CashPlacementSettlement $settlement = new CashPlacementSettlement,
    ) {}

    /**
     * @param  Collection<int, CashPlacement>|null  $placements  Preloaded, to avoid a query per sample date.
     */
    public function valueOn(Portfolio $portfolio, ?Carbon $asOf = null, ?Collection $placements = null): PlacementValuation
    {
        $placements ??= $this->placements($portfolio);
        $asOf ??= Carbon::today();

        if ($placements->isEmpty()) {
            return $this->valuator->valueOn([], $asOf);
        }

        $settled = $this->settlement->settledIds(
            $portfolio,
            $placements->map(static fn (CashPlacement $p): int => (int) $p->id)->all(),
        );

        return $this->valuator->valueOn($this->rows($placements, $settled), $asOf);
    }

    /**
     * Value at several dates from one load of the placements and one
     * settlement lookup — the shape a time series needs.
     *
     * @param  list<Carbon>  $dates
     * @return array<string, PlacementValuation> Keyed by Y-m-d.
     */
    public function valuesOn(Portfolio $portfolio, array $dates): array
    {
        $placements = $this->placements($portfolio);
        $out = [];

        foreach ($dates as $date) {
            $out[$date->toDateString()] = $this->valueOn($portfolio, $date, $placements);
        }

        return $out;
    }

    /**
     * @return Collection<int, CashPlacement>
     */
    public function placements(Portfolio $portfolio): Collection
    {
        return CashPlacement::query()
            ->where('portfolio_id', $portfolio->id)
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, CashPlacement>  $placements
     * @param  array<int, true>  $settled
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $placements, array $settled): array
    {
        return $placements->map(static fn (CashPlacement $placement): array => [
            'id' => (int) $placement->id,
            'label' => trim(sprintf(
                '%s %s',
                (string) ($placement->institution ?? ''),
                (string) ($placement->reference ?? ''),
            )) ?: null,
            'kind' => $placement->kind,
            'status' => $placement->status,
            'principal' => (float) $placement->principal,
            'interest_rate' => (float) $placement->interest_rate,
            'start_date' => $placement->start_date->copy(),
            'maturity_date' => $placement->maturity_date?->copy(),
            'closed_on' => $placement->closed_on?->copy(),
            'compounding_basis' => $placement->compounding_basis,
            'payout' => $placement->payout,
            'cash_settled' => isset($settled[(int) $placement->id]),
        ])->values()->all();
    }
}
