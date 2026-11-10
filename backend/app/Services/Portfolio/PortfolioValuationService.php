<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Models\FundNav;
use App\Models\Portfolio;
use App\Models\PortfolioHolding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Computes everything the portfolio endpoints and the AI prompts report.
 *
 * All values are derived from the holdings plus the latest close — nothing is
 * cached in the portfolio table, so a price update is reflected immediately
 * and there is no staleness bug to chase.
 *
 * Net worth is the one figure here that spans more than the equity book, so it
 * is also the one place a rupee can get counted twice. It is built from three
 * disjoint parts: marked-to-market equity, idle cash
 * (`Portfolio::investableCash()`), and *cash-settled* placements only. A
 * placement recorded without a cash settlement never debited an account, so
 * its principal is still inside `bank_balance` and is deliberately excluded
 * here — reported, with a reason, rather than added to the same money twice.
 * See {@see PlacementValuator} for the full rule set.
 */
final class PortfolioValuationService
{
    public function __construct(
        private readonly PlacementValuationService $placements = new PlacementValuationService,
        private readonly PortfolioTimeSeries $timeSeries = new PortfolioTimeSeries,
        private readonly MarkSourceReconciler $marks = new MarkSourceReconciler,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summarize(Portfolio $portfolio): array
    {
        $holdings = $portfolio->openHoldings()
            ->with(['stock.sector', 'stock.latestSignal'])
            ->get();

        $totalInvested = 0.0;
        $totalValue = 0.0;
        $valuedInvested = 0.0;  // invested amount for positions we could price
        $unpricedSymbols = [];

        foreach ($holdings as $holding) {
            $invested = $holding->investedAmount();
            $totalInvested += $invested;

            $value = $holding->currentValue();

            if ($value === null) {
                // No price yet (newly listed, or never scraped). Excluding it
                // from the return calculation is more honest than treating cost
                // as value, which would silently report a 0% gain.
                $unpricedSymbols[] = $holding->stock->symbol;

                continue;
            }

            $totalValue += $value;
            $valuedInvested += $invested;
        }

        $unrealizedGain = round($totalValue - $valuedInvested, 2);
        $unrealizedGainPercent = $valuedInvested > 0
            ? round($unrealizedGain / $valuedInvested * 100, 2)
            : 0.0;

        $realizedGain = round((float) $portfolio->holdings()->sum('realized_gain'), 2);
        $cash = $portfolio->investableCash();
        $placements = $this->placements->valueOn($portfolio);
        $placementValue = round($placements->netWorthContribution, 2);

        return [
            'total_invested' => round($totalInvested, 2),
            'total_value' => round($totalValue, 2),
            'unrealized_gain' => $unrealizedGain,
            'unrealized_gain_percent' => $unrealizedGainPercent,
            'realized_gain' => $realizedGain,
            'cash_in_hand' => round((float) $portfolio->cash_in_hand, 2),
            'bank_balance' => round((float) $portfolio->bank_balance, 2),
            'investable_cash' => $cash,
            // Three disjoint parts, so nothing is counted twice: equity marked
            // to market, idle cash, and only those placements whose principal
            // actually left a cash account. Placements stay out of
            // investable_cash (committed until maturity), so adding them here
            // cannot overlap with it.
            'net_worth' => round($totalValue + $cash + $placementValue, 2),
            'net_worth_components' => [
                'equity_market_value' => round($totalValue, 2),
                'investable_cash' => $cash,
                'settled_placements' => $placementValue,
            ],
            'placements' => $placements->toArray(),
            'holdings_count' => $holdings->count(),
            'unpriced_symbols' => $unpricedSymbols,
            // Where every mark in the figures above came from, and whether the
            // historical series agrees. See markReconciliation().
            'mark_reconciliation' => $this->markReconciliation($holdings),
            'sector_allocation' => $this->sectorAllocation($holdings, $totalValue),
            'concentration' => $this->concentration($holdings, $totalValue),
        ];
    }

    /**
     * Where each holding's mark came from, and whether the two mark sources
     * still disagree about it.
     *
     * The live figures above are marked from `stocks.last_price` via
     * `PortfolioHolding::currentValue()`, unchanged. That is deliberate: the
     * spot column is the freshest price the app has and it is the *only* price
     * for a MeroShare-imported stub, so switching this service to the
     * historical series would silently move net worth and unrealised gain on
     * every fund whose NAV row differs from its last quote. Positions are a
     * function of the ledger and have to stay reconcilable, so nothing is
     * rewritten here.
     *
     * What changes is that the divergence is no longer invisible. Every mark is
     * labelled with its source, its as-of date and how stale it is, and where
     * `PortfolioTimeSeries` would mark the same holding differently the pair is
     * reported symbol by symbol, with the rupee difference the two sources
     * imply. `series_value` is what the historical series makes the book worth
     * today; a difference against `spot_value` is a real disagreement to be
     * looked at, not rounding.
     *
     * @param  Collection<int, PortfolioHolding>  $holdings
     * @return array<string, mixed>
     */
    private function markReconciliation(Collection $holdings): array
    {
        if ($holdings->isEmpty()) {
            return [
                'as_of' => Carbon::today()->toDateString(),
                'spot_value' => 0.0,
                'series_value' => 0.0,
                'difference' => 0.0,
                'agrees' => true,
                'holdings' => [],
                'spot_only_symbols' => [],
                'stale_symbols' => [],
                'unpriced_symbols' => [],
                'disagreeing_symbols' => [],
                'note' => 'No open holdings, so there is nothing to mark.',
            ];
        }

        $today = Carbon::today();
        /** @var list<int> $stockIds */
        $stockIds = $holdings->pluck('stock_id')->map(static fn ($id): int => (int) $id)->all();
        $marks = $this->timeSeries->marksFor($stockIds, $today);

        $spotValue = 0.0;
        $seriesValue = 0.0;
        $rows = [];
        $spotOnly = [];
        $stale = [];
        $unpriced = [];
        $disagreeing = [];

        foreach ($holdings as $holding) {
            $symbol = $holding->stock->symbol;
            $quantity = (float) $holding->quantity;
            $mark = $marks[(int) $holding->stock_id] ?? null;

            $spotPrice = $holding->currentPrice();
            $spotHoldingValue = $holding->currentValue();
            $seriesHoldingValue = $mark?->valueOf($quantity);

            $spotValue += $spotHoldingValue ?? 0.0;
            $seriesValue += $seriesHoldingValue ?? 0.0;

            $divergence = $this->marks->divergence($mark?->price, $spotPrice);

            if ($mark === null || ! $mark->isPriced()) {
                $unpriced[] = $symbol;
            } elseif (! $mark->seriesBacked) {
                $spotOnly[] = $symbol;
            }

            if ($mark?->isStale === true) {
                $stale[] = $symbol;
            }

            if ($divergence['diverges']) {
                $disagreeing[] = $symbol;
            }

            $rows[] = [
                'symbol' => $symbol,
                'quantity' => round($quantity, 2),
                'spot_price' => $spotPrice,
                'spot_value' => $spotHoldingValue,
                'series_price' => $mark?->price,
                'series_value' => $seriesHoldingValue,
                'mark_source' => $mark?->source ?? HoldingMark::SOURCE_NONE,
                'mark_as_of' => $mark?->asOf?->toDateString(),
                'mark_stale_days' => $mark?->staleDays,
                'series_backed' => $mark?->seriesBacked ?? false,
                'price_difference' => $divergence['difference'],
                'price_difference_percent' => $divergence['difference_percent'],
                'diverges' => $divergence['diverges'],
                'mark_reason' => $mark?->reason ?? 'No mark was resolved for this holding.',
            ];
        }

        $difference = round($spotValue - $seriesValue, 2);

        return [
            'as_of' => $today->toDateString(),
            'spot_value' => round($spotValue, 2),
            'series_value' => round($seriesValue, 2),
            'difference' => $difference,
            'agrees' => abs($difference) < 0.01,
            'holdings' => $rows,
            'spot_only_symbols' => $spotOnly,
            'stale_symbols' => $stale,
            'unpriced_symbols' => $unpriced,
            'disagreeing_symbols' => $disagreeing,
            'note' => $this->reconciliationNote($difference, $spotOnly, $stale, $unpriced, $disagreeing),
        ];
    }

    /**
     * @param  list<string>  $spotOnly
     * @param  list<string>  $stale
     * @param  list<string>  $unpriced
     * @param  list<string>  $disagreeing
     */
    private function reconciliationNote(
        float $difference,
        array $spotOnly,
        array $stale,
        array $unpriced,
        array $disagreeing,
    ): string {
        $parts = [];

        if ($spotOnly !== []) {
            $parts[] = sprintf(
                '%s %s priced only from stocks.last_price — no row in stock_prices or fund_navs — so '
                    .'the mark is provisional and cannot value any date before it',
                implode(', ', $spotOnly),
                count($spotOnly) === 1 ? 'is' : 'are',
            );
        }

        if ($stale !== []) {
            $parts[] = sprintf(
                '%s %s a mark older than the staleness threshold',
                implode(', ', $stale),
                count($stale) === 1 ? 'carries' : 'carry',
            );
        }

        if ($unpriced !== []) {
            $parts[] = sprintf(
                '%s cannot be priced at all and %s excluded from every value figure rather than counted at cost',
                implode(', ', $unpriced),
                count($unpriced) === 1 ? 'is' : 'are',
            );
        }

        if ($disagreeing !== []) {
            $parts[] = sprintf(
                'the spot column and the price history disagree on %s, a difference of NPR %s across the book',
                implode(', ', $disagreeing),
                number_format($difference, 2),
            );
        }

        if ($parts === []) {
            return 'Every holding is marked from the stored price history and both mark sources agree.';
        }

        return ucfirst(implode('; ', $parts)).'.';
    }

    /**
     * @param  Collection<int, PortfolioHolding>  $holdings
     * @return list<array<string, mixed>>
     */
    public function sectorAllocation(Collection $holdings, float $totalValue): array
    {
        $bySector = [];

        foreach ($holdings as $holding) {
            $value = $holding->currentValue();

            if ($value === null) {
                continue;
            }

            $sector = $holding->stock->sector?->name ?? 'Unclassified';

            $bySector[$sector] ??= ['sector' => $sector, 'value' => 0.0, 'invested' => 0.0, 'symbols' => []];
            $bySector[$sector]['value'] += $value;
            $bySector[$sector]['invested'] += $holding->investedAmount();
            $bySector[$sector]['symbols'][] = $holding->stock->symbol;
        }

        $allocation = [];

        foreach ($bySector as $entry) {
            $allocation[] = [
                'sector' => $entry['sector'],
                'value' => round($entry['value'], 2),
                'invested' => round($entry['invested'], 2),
                'percent' => $totalValue > 0 ? round($entry['value'] / $totalValue * 100, 2) : 0.0,
                'symbols' => $entry['symbols'],
            ];
        }

        usort($allocation, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return $allocation;
    }

    /**
     * Per-position weights, largest first — the input to any concentration
     * judgement the model makes.
     *
     * @param  Collection<int, PortfolioHolding>  $holdings
     * @return list<array<string, mixed>>
     */
    public function concentration(Collection $holdings, float $totalValue): array
    {
        $positions = [];

        foreach ($holdings as $holding) {
            $value = $holding->currentValue();

            if ($value === null) {
                continue;
            }

            $positions[] = [
                'symbol' => $holding->stock->symbol,
                'value' => round($value, 2),
                'percent' => $totalValue > 0 ? round($value / $totalValue * 100, 2) : 0.0,
            ];
        }

        usort($positions, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return $positions;
    }

    /**
     * Detailed per-holding breakdown used by the API resource.
     *
     * @return list<array<string, mixed>>
     */
    public function holdingBreakdown(Portfolio $portfolio): array
    {
        $holdings = $portfolio->openHoldings()->with(['stock.sector', 'stock.latestSignal'])->get();
        $totalValue = 0.0;

        foreach ($holdings as $holding) {
            $totalValue += $holding->currentValue() ?? 0.0;
        }

        // Latest NAV for any fund units held. Fund P/L is reported on both a
        // market and a NAV basis, because the market price of a closed-end fund
        // can sit far from what the units are actually worth.
        $navs = $this->latestNavs($holdings);

        $rows = [];

        foreach ($holdings as $holding) {
            $value = $holding->currentValue();
            $nav = $navs->get($holding->stock_id);
            $isFund = $holding->stock->instrument_type === 'mutual_fund';

            $navValue = $nav === null ? null : round((float) $holding->quantity * (float) $nav->nav, 2);
            $invested = $holding->investedAmount();

            $rows[] = [
                'id' => $holding->id,
                'symbol' => $holding->stock->symbol,
                'name' => $holding->stock->name,
                'sector' => $holding->stock->sector?->name,
                'instrument_type' => $holding->stock->instrument_type,
                'quantity' => (float) $holding->quantity,
                'average_cost' => round((float) $holding->average_cost, 4),
                'invested' => $invested,
                'current_price' => $holding->currentPrice(),
                'current_value' => $value,
                'unrealized_gain' => $holding->unrealizedGain(),
                'unrealized_gain_percent' => $holding->unrealizedGainPercent(),
                'realized_gain' => round((float) $holding->realized_gain, 2),
                'weight_percent' => $totalValue > 0 && $value !== null
                    ? round($value / $totalValue * 100, 2)
                    : null,
                'signal' => $holding->stock->latestSignal?->signal?->value,
                'signal_confidence' => $holding->stock->latestSignal?->confidence,
                'first_purchased_on' => $holding->first_purchased_on?->toDateString(),

                // Fund-only fields; null for ordinary equities.
                'is_fund' => $isFund,
                'nav' => $nav === null ? null : round((float) $nav->nav, 4),
                'nav_date' => $nav?->nav_date->toDateString(),
                'nav_value' => $navValue,
                'nav_gain' => $navValue === null ? null : round($navValue - $invested, 2),
                'nav_gain_percent' => $navValue === null || $invested <= 0
                    ? null
                    : round(($navValue - $invested) / $invested * 100, 2),
                // Fall back to the live price when the stored figure is null,
                // which happens for funds that have no row in the daily price
                // table (they are not always traded).
                'premium_discount_percent' => $this->premiumDiscount($nav, $holding->currentPrice()),
                'discount_read' => $nav?->discountLabel(),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => ($b['current_value'] ?? 0) <=> ($a['current_value'] ?? 0));

        return $rows;
    }

    /**
     * Premium/discount of market price to NAV, preferring the figure stored
     * against the NAV row and computing it live otherwise.
     */
    private function premiumDiscount(?FundNav $nav, ?float $currentPrice): ?float
    {
        if ($nav === null) {
            return null;
        }

        if ($nav->premium_discount_percent !== null) {
            return round((float) $nav->premium_discount_percent, 4);
        }

        if ($currentPrice === null || $nav->nav <= 0) {
            return null;
        }

        return round(($currentPrice - (float) $nav->nav) / (float) $nav->nav * 100, 4);
    }

    /**
     * Most recent NAV per fund held, in one query rather than one per holding.
     *
     * @param  Collection<int, PortfolioHolding>  $holdings
     * @return Collection<int, FundNav> Keyed by stock_id.
     */
    private function latestNavs(Collection $holdings): Collection
    {
        $fundIds = $holdings
            ->filter(static fn ($holding): bool => $holding->stock->instrument_type === 'mutual_fund')
            ->pluck('stock_id')
            ->unique();

        if ($fundIds->isEmpty()) {
            return collect();
        }

        // One row per fund: the newest nav_date for each.
        $latestIds = FundNav::query()
            ->selectRaw('MAX(id) as id')
            ->whereIn('stock_id', $fundIds)
            ->whereIn('nav_date', function ($query) use ($fundIds): void {
                $query->selectRaw('MAX(nav_date)')
                    ->from('fund_navs as inner_navs')
                    ->whereColumn('inner_navs.stock_id', 'fund_navs.stock_id')
                    ->whereIn('inner_navs.stock_id', $fundIds);
            })
            ->groupBy('stock_id')
            ->pluck('id');

        return FundNav::whereIn('id', $latestIds)->get()->keyBy('stock_id');
    }
}
