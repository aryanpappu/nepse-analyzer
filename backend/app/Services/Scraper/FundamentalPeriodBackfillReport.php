<?php

declare(strict_types=1);

namespace App\Services\Scraper;

/**
 * What a {@see FundamentalPeriodBackfiller} pass did, in enough detail to
 * answer "how many rows could you date, and why not the rest".
 *
 * The per-period breakdown is the useful part. `fiscal_period` is written by
 * three different crawlers in three different shapes, so a single "846 dated"
 * number hides whether a whole format silently failed to parse — which is
 * exactly the failure this pass could have and never notice.
 */
final readonly class FundamentalPeriodBackfillReport
{
    /**
     * @param  array<string, array{total: int, exact: int, approximate: int, undated: int, reason: ?string}>  $byPeriod
     *                                                                                                                   Keyed by the raw fiscal_period string, in descending row count.
     * @param  array<string, int>  $undatedReasons  Reason => how many rows it left undated.
     */
    public function __construct(
        public int $examined,
        public int $dated,
        public int $exact,
        public int $approximate,
        public int $undated,
        public int $written,
        public bool $dryRun,
        public ?string $observedThrough,
        public array $byPeriod,
        public array $undatedReasons,
        public ?int $scrapeRunId = null,
    ) {}

    public function datedPercent(): float
    {
        return $this->examined === 0 ? 0.0 : round($this->dated / $this->examined * 100, 2);
    }
}
