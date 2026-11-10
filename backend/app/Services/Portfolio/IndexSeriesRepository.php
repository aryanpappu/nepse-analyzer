<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Models\MarketIndex;
use Illuminate\Support\Carbon;

/**
 * The one place an index slug is resolved and an index's level history is
 * loaded, so every benchmark in this namespace measures against exactly the
 * same series. {@see IndexBenchmarkService} and
 * {@see ReturnAttributionService} both go through here rather than each
 * writing their own query and their own config lookup — the same reason
 * `PerformanceService::cashFlow()` is public and static.
 *
 * Config read here (both with an env override, see `config/nepse.php`):
 *
 * - `nepse.benchmark.market_index_slug` — the whole-market index. A **slug**
 *   of the published name (`nepse-index`), not `nepse`.
 * - `nepse.benchmark.sector_index_slugs` — `sectors.slug` => sub-index slug.
 *   An unmapped sector falls back to its own slug, which already matches for
 *   several; where nothing is published under that slug the series comes back
 *   empty and the caller reports "no sub-index", never a 0% comparison.
 */
final class IndexSeriesRepository
{
    public function marketSlug(?string $override = null): string
    {
        return $override ?? (string) config('nepse.benchmark.market_index_slug', 'nepse');
    }

    /**
     * Sub-index slug for a sector, from the configured map, falling back to
     * the sector's own slug.
     */
    public function subIndexSlugFor(string $sectorSlug): string
    {
        $map = (array) config('nepse.benchmark.sector_index_slugs', []);

        return (string) ($map[$sectorSlug] ?? $sectorSlug);
    }

    /**
     * Whether the sector slug is explicitly mapped to a sub-index, as opposed
     * to falling back to its own slug. Debentures, government bonds,
     * preference and promoter shares have no sub-index at all, and this is
     * what lets a caller say so rather than implying a missing scrape.
     */
    public function hasMappedSubIndex(string $sectorSlug): bool
    {
        return array_key_exists($sectorSlug, (array) config('nepse.benchmark.sector_index_slugs', []));
    }

    /**
     * All levels of $slug up to $upTo, in one query — an index's full history
     * is a few thousand rows at most (one per trading day), so there is no
     * need to window it further before handing it to IndexSeries.
     *
     * @return array{series: IndexSeries, name: ?string}
     */
    public function load(string $slug, Carbon $upTo): array
    {
        $rows = MarketIndex::query()
            ->where('slug', $slug)
            ->where('trade_date', '<=', $upTo->toDateString())
            ->orderBy('trade_date')
            ->get(['name', 'trade_date', 'value']);

        $points = $rows->map(static fn (MarketIndex $row): array => [
            'date' => $row->trade_date->copy(),
            'value' => (float) $row->value,
        ])->all();

        return [
            'series' => new IndexSeries($points),
            'name' => $rows->last()?->name,
        ];
    }
}
