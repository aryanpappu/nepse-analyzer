<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use Illuminate\Support\Carbon;

/**
 * A crawl of the corporate calendar, together with the date the source says it
 * published it.
 *
 * Same contract as {@see PriceSnapshot}: `publishedOn` is read off the payload
 * — ShareSansar's proposed-dividend feed carries a `published_date` field on
 * every record — and is null when no feed stated one, never filled in from the
 * local clock. It is what the run is recorded against, so a crawl that ran at
 * midnight is filed under the day the source was actually publishing.
 *
 * Unlike a price snapshot this is not a single session's table. A corporate
 * calendar is forward looking: rows carry their own event dates and several
 * feeds are merged into one snapshot, so there is no one "trade date" the whole
 * payload belongs to.
 */
final readonly class CorporateActionSnapshot
{
    /**
     * @param  list<CorporateActionRow>  $rows
     * @param  array<string, string>  $feedErrors  Feed label => why it yielded nothing.
     */
    public function __construct(
        public ?Carbon $publishedOn,
        public array $rows,
        public array $feedErrors = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
