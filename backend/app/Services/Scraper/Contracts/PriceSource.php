<?php

declare(strict_types=1);

namespace App\Services\Scraper\Contracts;

use App\Services\Scraper\IndexSnapshot;
use App\Services\Scraper\PriceSnapshot;
use Illuminate\Support\Carbon;

/**
 * A crawlable source of NEPSE market data.
 *
 * Implementations own exactly one concern: turning a public HTML page into
 * normalised rows. They must not touch the database, and must throw
 * ScrapeException (never return partial garbage) when the markup no longer
 * matches, so the manager can fail over to the next source.
 *
 * key(), label(), supportsFundamentals() and fetchFundamentals() are inherited
 * from FundamentalsSource — a price source is a fundamentals source that can
 * additionally serve a daily price table.
 */
interface PriceSource extends FundamentalsSource
{
    /**
     * Daily price table, together with the session it belongs to.
     *
     * Implementations must report the date the *page* is showing, not the date
     * that was requested — these sites publish the last completed session and
     * frequently ignore a date filter, so trusting the request would write the
     * wrong day's prices into history.
     */
    public function fetchDailyPrices(?Carbon $date = null): PriceSnapshot;

    /**
     * NEPSE Index, Sensitive Index and the sector sub-indices, together with
     * the session they belong to.
     *
     * Same contract as fetchDailyPrices(): implementations report the date
     * the page is actually showing, not the date requested, and a source
     * that cannot serve indices at all returns an empty snapshot rather than
     * throwing — it is a supplementary capability, not every source's job.
     */
    public function fetchIndices(?Carbon $date = null): IndexSnapshot;
}
