<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

/**
 * Beta, correlation and alpha from a paired portfolio/index return series.
 *
 * Every statistic is null whenever the sample cannot support it honestly:
 * fewer than two paired observations, or an index with zero variance across
 * the window (a flat index has no slope to fit a beta against — the
 * portfolio's own return is still real and fully visible in the
 * money-weighted comparison, it just is not "explained" by index movement
 * that never happened).
 */
final class IndexRegressionResult
{
    public function __construct(
        public readonly int $sampleSize,
        public readonly ?float $beta,
        public readonly ?float $correlation,
        public readonly ?float $alphaPercentPerPeriod,
        public readonly ?float $alphaPercentAnnualized,
        public readonly string $interval,
        public readonly int $periodsPerYear,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sample_size' => $this->sampleSize,
            'interval' => $this->interval,
            'periods_per_year' => $this->periodsPerYear,
            'beta' => $this->beta,
            'correlation' => $this->correlation,
            'r_squared' => $this->correlation === null ? null : round($this->correlation ** 2, 4),
            'alpha_percent_per_period' => $this->alphaPercentPerPeriod,
            // A simple periods-per-year scaling, not compounded: alpha is an
            // average excess return per interval, not a rate that compounds
            // the way a price series does.
            'alpha_percent_annualized' => $this->alphaPercentAnnualized,
        ];
    }
}
