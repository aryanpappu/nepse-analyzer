<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use Illuminate\Support\Carbon;

/**
 * One historical daily reading of a named index.
 *
 * Distinct from {@see HistoricalBar} because it carries a name (a bar always
 * belongs to one of several named indices, whereas a price bar belongs to
 * whichever single symbol the caller asked for) and a turnover figure instead
 * of volume — ShareSansar's index history feed reports open/high/low/close
 * and turnover for an index, never a share count.
 */
final readonly class IndexHistoryBar
{
    public function __construct(
        public string $name,
        public Carbon $date,
        public float $value,
        public ?float $open = null,
        public ?float $high = null,
        public ?float $low = null,
        public ?float $changeAmount = null,
        public ?float $changePercent = null,
        public ?float $totalTurnover = null,
    ) {}

    public function slug(): string
    {
        return (new IndexRow($this->name, $this->value))->slug();
    }
}
