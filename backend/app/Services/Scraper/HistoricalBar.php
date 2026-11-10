<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use Illuminate\Support\Carbon;

/**
 * One daily OHLCV bar from a historical chart feed.
 */
final readonly class HistoricalBar
{
    public function __construct(
        public Carbon $date,
        public float $close,
        public ?float $open = null,
        public ?float $high = null,
        public ?float $low = null,
        public int $volume = 0,
    ) {}
}
