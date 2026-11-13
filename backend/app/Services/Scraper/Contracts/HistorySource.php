<?php

declare(strict_types=1);

namespace App\Services\Scraper\Contracts;

use App\Services\Scraper\HistoricalBar;
use Illuminate\Support\Carbon;

/**
 * A source of historical daily bars for a single scrip.
 *
 * Separate from PriceSource because the daily price tables only ever publish
 * the latest session — backfilling history needs a per-symbol chart feed, which
 * is a different shape of request entirely.
 */
interface HistorySource
{
    public function key(): string;

    /**
     * Daily bars for one symbol, oldest first.
     *
     * Returns an empty array when the feed has no data for the symbol (newly
     * listed scrips, debentures, delisted tickers) rather than throwing.
     *
     * `$since` is an early-stop hint for sources that page backwards through
     * several requests: once a response already reaches back to that date,
     * stop asking for older windows. It never trims what was returned, and a
     * single-request source may ignore it.
     *
     * @return list<HistoricalBar>
     */
    public function fetchHistory(string $symbol, int $days, ?Carbon $since = null): array;
}
