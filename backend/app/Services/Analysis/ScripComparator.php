<?php

declare(strict_types=1);

namespace App\Services\Analysis;

use App\Models\Stock;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Head-to-head comparison of two or more scrips, metric by metric.
 *
 * This is the "which of these two banks is better" table, built properly:
 *
 * - Only metrics with a defensible direction are scored. Share price and market
 *   capitalisation are shown for context and explicitly not counted, because
 *   handing the win to whichever share is numerically cheaper rewards a low
 *   price for its own sake.
 * - A metric is only contested when *every* side reports it. Awarding a point
 *   because the other company did not publish a figure measures disclosure, not
 *   quality.
 * - Percentages are compared as percentages. Published tables compare rupee
 *   gaps to the 52-week high across scrips at different price levels, which is
 *   not a comparison at all.
 * - Ties are recorded as ties rather than broken arbitrarily.
 */
final class ScripComparator
{
    /** Comparing more than this stops being a comparison and becomes a screen. */
    private const MAX_SCRIPS = 4;

    /**
     * Two values within this relative distance are treated as equal. Filed
     * figures are rounded to two decimals at source, so an 11.22 vs 11.2201 is
     * noise rather than an edge.
     */
    private const TIE_EPSILON = 1e-6;

    /**
     * @param  list<string>  $symbols
     *
     * @throws InvalidArgumentException
     */
    public function compare(array $symbols): array
    {
        $symbols = array_values(array_unique(array_map(
            static fn (string $s): string => strtoupper(trim($s)),
            array_filter($symbols, static fn (string $s): bool => trim($s) !== ''),
        )));

        if (count($symbols) < 2) {
            throw new InvalidArgumentException('Comparing needs at least two distinct symbols.');
        }

        if (count($symbols) > self::MAX_SCRIPS) {
            throw new InvalidArgumentException('At most '.self::MAX_SCRIPS.' scrips can be compared at once.');
        }

        $stocks = Stock::query()
            ->whereIn('symbol', $symbols)
            ->with(FundamentalSnapshot::relationsToLoad())
            ->get()
            ->keyBy('symbol');

        $missing = array_values(array_diff($symbols, $stocks->keys()->all()));

        if ($missing !== []) {
            throw new InvalidArgumentException('Unknown symbol(s): '.implode(', ', $missing));
        }

        /** @var list<FundamentalSnapshot> $snapshots */
        $snapshots = [];

        foreach ($symbols as $symbol) {
            $snapshots[] = FundamentalSnapshot::for($stocks[$symbol]);
        }

        return $this->compareSnapshots($snapshots);
    }

    /**
     * The comparison proper: pure, and the part worth testing.
     *
     * Split from compare() so the scoring rules can be exercised without a
     * database, and so a caller can compare a scrip against figures that are
     * not in the database at all.
     *
     * @param  list<FundamentalSnapshot>  $snapshots
     * @return array<string, mixed>
     */
    public function compareSnapshots(array $snapshots): array
    {
        $symbols = array_map(static fn (FundamentalSnapshot $s): string => $s->symbol, $snapshots);

        $rows = [];
        $wins = array_fill_keys($symbols, 0);
        $contested = 0;

        foreach (MetricCatalog::all() as $metric) {
            $values = [];

            foreach ($snapshots as $snapshot) {
                $values[$snapshot->symbol] = $snapshot->get($metric->key);
            }

            $winner = null;
            $isTie = false;

            if ($metric->isScored() && ! in_array(null, $values, true)) {
                [$winner, $isTie] = $this->pickWinner($metric, $values);
                $contested++;

                if ($winner !== null) {
                    $wins[$winner]++;
                }
            }

            $rows[] = [
                'key' => $metric->key,
                'label' => $metric->label,
                'unit' => $metric->unit->value,
                'better' => $metric->better->value,
                'rationale' => $metric->rationale,
                'scored' => $metric->isScored(),
                'contested' => $metric->isScored() && ! in_array(null, $values, true),
                'winner' => $winner,
                'tie' => $isTie,
                'values' => array_map(
                    static fn (?float $v): array => [
                        'raw' => $v,
                        'display' => $metric->format($v),
                    ],
                    $values,
                ),
            ];
        }

        return [
            'symbols' => $symbols,
            'scrips' => array_map(static fn (FundamentalSnapshot $s): array => [
                'symbol' => $s->symbol,
                'name' => $s->name,
                'sector' => $s->sector,
                'fiscal_period' => $s->fiscalPeriod,
                'data_coverage_percent' => $s->coverage(),
            ], $snapshots),
            'metrics' => $rows,
            'scoreboard' => $wins,
            'contested_metrics' => $contested,
            'verdict' => $this->verdict($wins, $contested),
        ];
    }

    /**
     * @param  array<string, float|null>  $values
     * @return array{0: string|null, 1: bool} [winner, wasTie]
     */
    private function pickWinner(MetricDefinition $metric, array $values): array
    {
        $best = null;
        $bestSymbol = null;
        $tied = false;

        foreach ($values as $symbol => $value) {
            if ($best === null) {
                $best = $value;
                $bestSymbol = $symbol;

                continue;
            }

            if ($this->isTie($best, $value)) {
                $tied = true;

                continue;
            }

            if ($metric->better->compare($value, $best) > 0) {
                $best = $value;
                $bestSymbol = $symbol;
                $tied = false;
            }
        }

        return $tied ? [null, true] : [$bestSymbol, false];
    }

    private function isTie(float $a, float $b): bool
    {
        $scale = max(abs($a), abs($b), 1.0);

        return abs($a - $b) / $scale < self::TIE_EPSILON;
    }

    /**
     * @param  array<string, int>  $wins
     * @return array{winner: string|null, margin: int, note: string}
     */
    private function verdict(array $wins, int $contested): array
    {
        if ($contested === 0) {
            return [
                'winner' => null,
                'margin' => 0,
                'note' => 'No metric is reported by every scrip, so there is nothing to compare on.',
            ];
        }

        arsort($wins);
        $ranked = array_keys($wins);
        $top = $ranked[0];
        $topScore = $wins[$top];
        $runnerUp = $wins[$ranked[1]] ?? 0;

        if ($topScore === $runnerUp) {
            return [
                'winner' => null,
                'margin' => 0,
                'note' => sprintf(
                    'Level at %d of %d contested metrics each — the difference is not in these numbers.',
                    $topScore,
                    $contested,
                ),
            ];
        }

        return [
            'winner' => $top,
            'margin' => $topScore - $runnerUp,
            'note' => sprintf(
                '%s leads on %d of %d contested metrics. This counts metrics, not importance — '
                .'read the per-metric rows before acting on the tally.',
                $top,
                $topScore,
                $contested,
            ),
        ];
    }

    /**
     * Where a scrip sits against its own sector, metric by metric.
     *
     * Percentile rather than rank, so the answer means the same thing in a
     * 3-company sector and a 90-company one. Scrips that do not report the
     * metric are excluded from its population instead of counted as zero.
     *
     * @return array<string, array{percentile: float, sample: int, better: string}>
     */
    public function sectorPercentiles(Stock $stock): array
    {
        if ($stock->sector_id === null) {
            return [];
        }

        $peers = Stock::query()
            ->active()
            ->where('sector_id', $stock->sector_id)
            ->where('instrument_type', $stock->instrument_type)
            ->with(FundamentalSnapshot::relationsToLoad())
            ->get();

        if ($peers->count() < 3) {
            return [];
        }

        $snapshots = $peers->map(static fn (Stock $s): FundamentalSnapshot => FundamentalSnapshot::for($s));
        $subject = FundamentalSnapshot::for($stock);
        $out = [];

        foreach (MetricCatalog::scored() as $metric) {
            $mine = $subject->get($metric->key);

            if ($mine === null) {
                continue;
            }

            $population = $snapshots
                ->map(static fn (FundamentalSnapshot $s): ?float => $s->get($metric->key))
                ->filter(static fn (?float $v): bool => $v !== null)
                ->values();

            if ($population->count() < 3) {
                continue;
            }

            $out[$metric->key] = [
                'percentile' => $this->percentile($metric, $mine, $population),
                'sample' => $population->count(),
                'better' => $metric->better->value,
            ];
        }

        return $out;
    }

    /**
     * Share of the sector this scrip beats on the metric, 0-100.
     *
     * @param  Collection<int, float>  $population
     */
    private function percentile(MetricDefinition $metric, float $value, Collection $population): float
    {
        $beaten = $population->filter(
            static fn (float $other): bool => $metric->better->compare($value, $other) > 0,
        )->count();

        return round($beaten / $population->count() * 100, 1);
    }
}
