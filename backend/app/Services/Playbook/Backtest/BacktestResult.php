<?php

declare(strict_types=1);

namespace App\Services\Playbook\Backtest;

/**
 * The whole answer: coverage first, then performance, then the market.
 *
 * Coverage is the first field on purpose. Read in the other order it is
 * possible to quote a hit rate from a slice that resolved 12% of its plans
 * against 40% of the index window, which is the mistake the portfolio
 * benchmark already made once.
 */
final class BacktestResult
{
    /**
     * @param  array<string, ?TradeStatistics>  $byGrade  Grade value => stats, across all profiles.
     * @param  array<string, ?TradeStatistics>  $byProfile  Profile value => stats, across all grades.
     * @param  array<string, array<string, ?TradeStatistics>>  $byProfileGrade  Profile => grade => stats.
     * @param  array<string, IndexComparison>  $indexByGrade  Grade value => comparison.
     * @param  array<string, array<string, IndexComparison>>  $indexByProfileGrade  Profile => grade => comparison.
     * @param  array<string, mixed>  $costSchedule
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public readonly BacktestCoverage $coverage,
        public readonly ?TradeStatistics $overall,
        public readonly array $byGrade,
        public readonly array $byProfile,
        public readonly array $byProfileGrade,
        public readonly IndexComparison $indexOverall,
        public readonly array $indexByGrade,
        public readonly array $indexByProfileGrade,
        public readonly array $costSchedule,
        public readonly array $settings,
        public readonly float $durationSeconds,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $stats = static fn (?TradeStatistics $s): ?array => $s?->toArray();

        return [
            'coverage' => $this->coverage->toArray(),
            'settings' => $this->settings,
            'cost_schedule' => $this->costSchedule,
            'overall' => $stats($this->overall),
            'by_grade' => array_map($stats, $this->byGrade),
            'by_profile' => array_map($stats, $this->byProfile),
            'by_profile_grade' => array_map(
                static fn (array $grades): array => array_map($stats, $grades),
                $this->byProfileGrade,
            ),
            'index_overall' => $this->indexOverall->toArray(),
            'index_by_grade' => array_map(
                static fn (IndexComparison $c): array => $c->toArray(),
                $this->indexByGrade,
            ),
            'index_by_profile_grade' => array_map(
                static fn (array $grades): array => array_map(
                    static fn (IndexComparison $c): array => $c->toArray(),
                    $grades,
                ),
                $this->indexByProfileGrade,
            ),
            'duration_seconds' => $this->durationSeconds,
        ];
    }
}
