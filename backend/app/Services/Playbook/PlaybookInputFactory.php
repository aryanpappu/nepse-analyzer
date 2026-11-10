<?php

declare(strict_types=1);

namespace App\Services\Playbook;

use App\Models\Stock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Batches the window aggregates the engine needs.
 *
 * Range highs/lows and session counts are the only things not already sitting
 * on a stock's latest indicator row, so they are fetched for the whole set in
 * three grouped queries instead of per scrip. That is the difference between
 * scoring the market in two seconds and in two minutes.
 */
final class PlaybookInputFactory
{
    /**
     * @param  Collection<int, Stock>  $stocks  With stock.latestIndicator and stock.latestFundamental eager loaded.
     * @return Collection<int, PlaybookInputs> Keyed by stock id.
     */
    public function forStocks(Collection $stocks, ?Carbon $asOf = null): Collection
    {
        $asOf = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $ids = $stocks->pluck('id')->all();

        if ($ids === []) {
            return collect();
        }

        $window20 = $this->rangeAggregate($ids, $asOf->copy()->subDays(30), $asOf);
        $window52w = $this->rangeAggregate($ids, $asOf->copy()->subDays(365), $asOf);
        $counts = DB::table('stock_prices')
            ->select('stock_id', DB::raw('count(*) as sessions'), DB::raw('max(trade_date) as last_date'))
            ->whereIn('stock_id', $ids)
            ->groupBy('stock_id')
            ->get()
            ->keyBy('stock_id');

        $latestVolumes = DB::table('stock_prices as p')
            ->select('p.stock_id', 'p.volume')
            ->joinSub(
                DB::table('stock_prices')
                    ->select('stock_id', DB::raw('max(trade_date) as trade_date'))
                    ->whereIn('stock_id', $ids)
                    ->groupBy('stock_id'),
                'latest',
                static function ($join): void {
                    $join->on('latest.stock_id', '=', 'p.stock_id')
                        ->on('latest.trade_date', '=', 'p.trade_date');
                },
            )
            ->get()
            ->keyBy('stock_id');

        return $stocks->mapWithKeys(function (Stock $stock) use ($window20, $window52w, $counts, $latestVolumes): array {
            $w20 = $window20->get($stock->id);
            $w52 = $window52w->get($stock->id);
            $volume = $latestVolumes->get($stock->id)?->volume;

            return [$stock->id => new PlaybookInputs(
                stock: $stock,
                price: (float) ($stock->last_price ?? 0),
                indicator: $stock->latestIndicator,
                fundamental: $stock->latestFundamental,
                sessions: (int) ($counts->get($stock->id)?->sessions ?? 0),
                high20: $w20?->high === null ? null : (float) $w20->high,
                low20: $w20?->low === null ? null : (float) $w20->low,
                high52w: $w52?->high === null ? null : (float) $w52->high,
                low52w: $w52?->low === null ? null : (float) $w52->low,
                volume: $volume === null ? null : (float) $volume,
            )];
        });
    }

    public function forStock(Stock $stock, ?Carbon $asOf = null): ?PlaybookInputs
    {
        $stock->loadMissing(['latestIndicator', 'latestFundamental']);

        return $this->forStocks(collect([$stock]), $asOf)->get($stock->id);
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, object>
     */
    private function rangeAggregate(array $ids, Carbon $from, Carbon $to): Collection
    {
        return DB::table('stock_prices')
            ->select(
                'stock_id',
                // High/low columns are nullable on older scraped rows, so fall
                // back to the close rather than dropping the window entirely.
                DB::raw('max(coalesce(high, close)) as high'),
                DB::raw('min(coalesce(low, close)) as low'),
            )
            ->whereIn('stock_id', $ids)
            ->whereBetween('trade_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('stock_id')
            ->get()
            ->keyBy('stock_id');
    }
}
