<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Enums\TransactionType;
use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the portfolio was worth on a past date.
 *
 * Nothing records this, and nothing needs to: positions are a pure function of
 * the transaction ledger, and every scrip's closes are already stored. So the
 * value on any date is recoverable by replaying the ledger up to that date and
 * marking the resulting holdings at that date's prices — no snapshot table, and
 * no waiting a year to start accumulating one.
 *
 * The limit is price history, not method. Where a held scrip has no close on or
 * before the date, the position is reported as unpriced rather than valued at
 * cost — treating cost as value would silently invent a 0% return for it.
 *
 * **Which mark applies is not decided here.** It is decided by
 * {@see MarkSourceReconciler}, which `PortfolioValuationService` consults too,
 * because the two used to disagree: a scrip carrying `stocks.last_price` with
 * no row in `stock_prices` or `fund_navs` (a MeroShare-seeded stub — NICADF is
 * one) was priced by the valuation service and dropped by this one, so the same
 * portfolio had two market values. The reconciler's precedence is series first,
 * then a *dated* spot price for dates on or after `last_traded_on`, then
 * unpriced. A spot mark is admitted, flagged `series_backed = false`, and
 * counted in `spot_marked_symbols`, so a period marked partly on a provisional
 * price says so instead of quietly excluding the holding.
 *
 * **Equity only, deliberately.** `valueOn()` is the input to the equity-vs-FD
 * and equity-vs-index comparisons and to the beta/alpha return series, so
 * folding fixed-deposit value into it would contaminate all three: deposit
 * interest compared against a hypothetical deposit is a guaranteed tie, and it
 * would flatten the return series beta is fitted to. A placement's value at a
 * date comes from {@see PlacementValuationService::valueOn()} instead, and the
 * two are added only where a *net worth* figure is what is wanted.
 */
final class PortfolioTimeSeries
{
    public function __construct(
        private readonly MarkSourceReconciler $marks = new MarkSourceReconciler,
    ) {}

    /**
     * Market value of the portfolio's equity at the close of $date.
     *
     * @param  Collection<int, PortfolioTransaction>|null  $transactions  Preloaded ledger, oldest first.
     * @return array{value: float, positions: int, priced: int, unpriced: int, unpriced_symbols: list<string>, complete: bool, known: bool, series_backed: int, spot_marked: int, spot_marked_symbols: list<string>, stale_marked_symbols: list<string>, series_complete: bool, provisional: bool, mark_note: string}
     */
    public function valueOn(Portfolio $portfolio, Carbon $date, ?Collection $transactions = null): array
    {
        $transactions ??= $this->ledger($portfolio);
        $rows = $this->positionValuesOn($transactions, $date);

        if ($rows === []) {
            // No holdings is a real, complete answer of zero — not missing data.
            return [
                'value' => 0.0, 'positions' => 0, 'priced' => 0, 'unpriced' => 0,
                'unpriced_symbols' => [], 'complete' => true, 'known' => true,
                'series_backed' => 0, 'spot_marked' => 0, 'spot_marked_symbols' => [],
                'stale_marked_symbols' => [], 'series_complete' => true, 'provisional' => false,
                'mark_note' => 'No positions held on this date.',
            ];
        }

        $value = 0.0;
        $priced = 0;
        $seriesBacked = 0;
        $unpriced = [];
        $spotMarked = [];
        $staleMarked = [];

        foreach ($rows as $stockId => $row) {
            if ($row['value'] === null) {
                $unpriced[] = $stockId;

                continue;
            }

            $value += $row['value'];
            $priced++;

            if ($row['series_backed']) {
                $seriesBacked++;
            } else {
                $spotMarked[] = $stockId;
            }

            if ($row['mark_is_stale']) {
                $staleMarked[] = $stockId;
            }
        }

        // Two symbol lookups at most, and only when there is something to name.
        $spotSymbols = $this->symbolsFor($spotMarked);
        $staleSymbols = $this->symbolsFor($staleMarked);
        $unpricedSymbols = $this->symbolsFor($unpriced);

        return [
            'value' => round($value, 2),
            'positions' => count($rows),
            'priced' => $priced,
            'unpriced' => count($unpriced),
            'unpriced_symbols' => $unpricedSymbols,
            'complete' => $unpriced === [],
            // Held positions with not one price between them is not a portfolio
            // worth zero — it is a date before the price history reaches. Saying
            // zero there would invent a total loss.
            'known' => $priced > 0,

            // Mark provenance. `complete` says every position carries a price;
            // these say what kind of price, which is a different question and
            // the one that used to go unasked.
            'series_backed' => $seriesBacked,
            'spot_marked' => count($spotMarked),
            'spot_marked_symbols' => $spotSymbols,
            'stale_marked_symbols' => $staleSymbols,
            'series_complete' => $unpriced === [] && $spotMarked === [],
            'provisional' => $spotMarked !== [],
            'mark_note' => $this->markNote(count($rows), $unpricedSymbols, $spotSymbols),
        ];
    }

    /**
     * Value at several dates in one pass.
     *
     * @param  list<Carbon>  $dates
     * @return array<string, array<string, mixed>> Keyed by Y-m-d; each entry is a valueOn() result.
     */
    public function valuesOn(Portfolio $portfolio, array $dates): array
    {
        $transactions = $this->ledger($portfolio);
        $out = [];

        foreach ($dates as $date) {
            $out[$date->toDateString()] = $this->valueOn($portfolio, $date, $transactions);
        }

        return $out;
    }

    /**
     * Per-scrip quantity, mark and value at the close of $date.
     *
     * The per-position layer `valueOn()` sums, exposed because attribution
     * needs the same replay broken out by scrip rather than totalled — and
     * needs it from the *same* marks, or a holding's contribution would not
     * add up to the portfolio figure it is being decomposed from.
     *
     * `value` is null, never 0.0, where the scrip has no mark on or before
     * $date: an unpriced position is unknown, not worthless.
     *
     * @param  Collection<int, PortfolioTransaction>  $transactions  Preloaded ledger, oldest first.
     * @return array<int, array{quantity: float, price: ?float, value: ?float, mark_source: string, mark_as_of: ?string, mark_stale_days: ?int, mark_is_stale: bool, series_backed: bool, mark_reason: string}>
     *                                                                                                                                                                                                          Keyed by stock_id.
     */
    public function positionValuesOn(Collection $transactions, Carbon $date): array
    {
        $positions = $this->positionsOn($transactions, $date);

        if ($positions === []) {
            return [];
        }

        $marks = $this->marksOn(array_keys($positions), $date);
        $rows = [];

        foreach ($positions as $stockId => $quantity) {
            $mark = $marks[$stockId] ?? null;

            $rows[$stockId] = [
                'quantity' => $quantity,
                'price' => $mark?->price,
                'value' => $mark?->valueOf($quantity),
                'mark_source' => $mark?->source ?? HoldingMark::SOURCE_NONE,
                'mark_as_of' => $mark?->asOf?->toDateString(),
                'mark_stale_days' => $mark?->staleDays,
                'mark_is_stale' => $mark?->isStale ?? false,
                'series_backed' => $mark?->seriesBacked ?? false,
                'mark_reason' => $mark?->reason ?? 'No mark was resolved for this position.',
            ];
        }

        return $rows;
    }

    /**
     * Units held per scrip at the close of $date.
     *
     * Only quantity is tracked: valuing a past date needs units and a price,
     * not a cost basis, and replaying average cost as well would be work the
     * caller throws away.
     *
     * @param  Collection<int, PortfolioTransaction>  $transactions
     * @return array<int, float> stock_id => quantity, zero positions removed.
     */
    public function positionsOn(Collection $transactions, Carbon $date): array
    {
        $cutoff = $date->copy()->endOfDay();
        $positions = [];

        foreach ($transactions as $transaction) {
            if ($transaction->transacted_on->greaterThan($cutoff)) {
                // The ledger is ordered, so the first future transaction ends it.
                break;
            }

            $stockId = $transaction->stock_id;
            $positions[$stockId] ??= 0.0;
            $quantity = (float) $transaction->quantity;

            $positions[$stockId] += match ($transaction->type) {
                TransactionType::Buy, TransactionType::Rights, TransactionType::Bonus => $quantity,
                TransactionType::Sell => -min($quantity, $positions[$stockId]),
                TransactionType::Dividend => 0.0,
            };
        }

        return array_filter(
            array_map(static fn (float $q): float => round($q, 2), $positions),
            static fn (float $q): bool => $q > 0.001,
        );
    }

    /**
     * The reconciled mark for a set of scrips at a date, without replaying a
     * ledger to find out what is held.
     *
     * Public so `PortfolioValuationService` can label its own marks from the
     * same decision this class values positions with — the two disagreeing
     * about one holding is the bug this exists to close, and a second copy of
     * the precedence rule would reopen it.
     *
     * @param  list<int>  $stockIds
     * @return array<int, HoldingMark>
     */
    public function marksFor(array $stockIds, Carbon $date): array
    {
        return $this->marksOn(array_values($stockIds), $date);
    }

    /**
     * A mark per scrip for $date, decided by {@see MarkSourceReconciler}.
     *
     * Closed-end funds do not appear in the daily price table — they are
     * tracked by NAV — so a scrip missing from stock_prices is looked up in
     * fund_navs. A scrip in neither falls to its dated `stocks.last_price`,
     * which is the only thing that prices a MeroShare-imported stub, and only
     * for dates on or after the date that price carries.
     *
     * Three queries at most per date, whatever the position count, and the
     * second and third are skipped when the first answered everything.
     *
     * @param  list<int>  $stockIds
     * @return array<int, HoldingMark>
     */
    private function marksOn(array $stockIds, Carbon $date): array
    {
        if ($stockIds === []) {
            return [];
        }

        $series = $this->closesOn($stockIds, $date);
        $missing = array_values(array_diff($stockIds, array_keys($series)));

        if ($missing !== []) {
            $series += $this->navsOn($missing, $date);
            $missing = array_values(array_diff($stockIds, array_keys($series)));
        }

        $spot = $missing === [] ? [] : $this->spotMarksFor($missing);
        $threshold = $this->staleAfterDays();
        $marks = [];

        foreach ($stockIds as $stockId) {
            $row = $series[$stockId] ?? null;
            $spotRow = $spot[$stockId] ?? null;

            $marks[$stockId] = $this->marks->reconcile(
                stockId: $stockId,
                asAt: $date,
                seriesPrice: $row['price'] ?? null,
                seriesDate: isset($row['date']) ? Carbon::parse($row['date'])->startOfDay() : null,
                seriesSource: $row['source'] ?? null,
                spotPrice: $spotRow['price'] ?? null,
                spotDate: isset($spotRow['date']) && $spotRow['date'] !== null
                    ? Carbon::parse($spotRow['date'])->startOfDay()
                    : null,
                staleAfterDays: $threshold,
            );
        }

        return $marks;
    }

    /**
     * `stocks.last_price` with the date it belongs to.
     *
     * Fetched only for scrips no series row could price. `last_traded_on` is
     * selected alongside because a price without its date is unusable here —
     * the reconciler refuses an undated spot mark rather than applying it to
     * whatever date happens to be asked for.
     *
     * @param  list<int>  $stockIds
     * @return array<int, array{price: float, date: ?string}>
     */
    private function spotMarksFor(array $stockIds): array
    {
        return DB::table('stocks')
            ->select('id', 'last_price', 'last_traded_on')
            ->whereIn('id', $stockIds)
            ->whereNotNull('last_price')
            ->where('last_price', '>', 0)
            ->get()
            ->mapWithKeys(static fn ($row): array => [
                (int) $row->id => [
                    'price' => (float) $row->last_price,
                    'date' => $row->last_traded_on === null
                        ? null
                        : (string) $row->last_traded_on,
                ],
            ])
            ->all();
    }

    /**
     * Last traded price, or failing that NAV, on or before $date for funds.
     *
     * Market price is preferred over NAV because that is what the units would
     * actually have fetched; NAV is the fallback for a fund with no quote.
     *
     * @param  list<int>  $stockIds
     * @return array<int, array{price: float, date: string, source: string}>
     */
    private function navsOn(array $stockIds, Carbon $date): array
    {
        $latest = DB::table('fund_navs')
            ->select('stock_id', DB::raw('max(nav_date) as nav_date'))
            ->whereIn('stock_id', $stockIds)
            ->where('nav_date', '<=', $date->toDateString())
            ->groupBy('stock_id');

        return DB::table('fund_navs as f')
            ->select('f.stock_id', 'f.nav_date', DB::raw('coalesce(f.market_price, f.nav) as mark'))
            ->joinSub($latest, 'l', static function ($join): void {
                $join->on('l.stock_id', '=', 'f.stock_id')
                    ->on('l.nav_date', '=', 'f.nav_date');
            })
            ->get()
            ->filter(static fn ($row): bool => $row->mark !== null && (float) $row->mark > 0.0)
            ->mapWithKeys(static fn ($row): array => [
                (int) $row->stock_id => [
                    'price' => (float) $row->mark,
                    'date' => (string) $row->nav_date,
                    'source' => HoldingMark::SOURCE_NAV,
                ],
            ])
            ->all();
    }

    /**
     * Last close on or before $date for each scrip.
     *
     * "On or before" rather than "on": a scrip that did not trade that session
     * is still worth its last traded price, and year boundaries routinely fall
     * on non-trading days. The date comes back with the price because how far
     * back the mark reaches is part of the answer.
     *
     * @param  list<int>  $stockIds
     * @return array<int, array{price: float, date: string, source: string}>
     */
    private function closesOn(array $stockIds, Carbon $date): array
    {
        if ($stockIds === []) {
            return [];
        }

        $latest = DB::table('stock_prices')
            ->select('stock_id', DB::raw('max(trade_date) as trade_date'))
            ->whereIn('stock_id', $stockIds)
            ->where('trade_date', '<=', $date->toDateString())
            ->groupBy('stock_id');

        return DB::table('stock_prices as p')
            ->select('p.stock_id', 'p.trade_date', 'p.close')
            ->joinSub($latest, 'l', static function ($join): void {
                $join->on('l.stock_id', '=', 'p.stock_id')
                    ->on('l.trade_date', '=', 'p.trade_date');
            })
            ->get()
            ->mapWithKeys(static fn ($row): array => [
                (int) $row->stock_id => [
                    'price' => (float) $row->close,
                    'date' => (string) $row->trade_date,
                    'source' => HoldingMark::SOURCE_CLOSE,
                ],
            ])
            ->all();
    }

    private function staleAfterDays(): int
    {
        return (int) config(
            'nepse.marks.spot_stale_after_days',
            MarkSourceReconciler::DEFAULT_STALE_AFTER_DAYS,
        );
    }

    /**
     * @param  list<string>  $unpricedSymbols
     * @param  list<string>  $spotSymbols
     */
    private function markNote(int $positions, array $unpricedSymbols, array $spotSymbols): string
    {
        if ($unpricedSymbols === [] && $spotSymbols === []) {
            return sprintf(
                'All %d position%s marked from the stored price history.',
                $positions,
                $positions === 1 ? '' : 's',
            );
        }

        $parts = [];

        if ($spotSymbols !== []) {
            $parts[] = sprintf(
                '%s marked from stocks.last_price with no price history behind it, so this value is provisional',
                implode(', ', $spotSymbols),
            );
        }

        if ($unpricedSymbols !== []) {
            $parts[] = sprintf(
                '%s could not be priced at all and %s excluded from the value',
                implode(', ', $unpricedSymbols),
                count($unpricedSymbols) === 1 ? 'is' : 'are',
            );
        }

        return ucfirst(implode('; ', $parts)).'.';
    }

    /**
     * @return Collection<int, PortfolioTransaction>
     */
    private function ledger(Portfolio $portfolio): Collection
    {
        return $portfolio->transactions()
            ->orderBy('transacted_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<int>  $stockIds
     * @return list<string>
     */
    private function symbolsFor(array $stockIds): array
    {
        if ($stockIds === []) {
            return [];
        }

        return DB::table('stocks')->whereIn('id', $stockIds)->pluck('symbol')->all();
    }
}
