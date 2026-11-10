<?php

declare(strict_types=1);

namespace App\Services\Scraper\Contracts;

use App\Services\Scraper\HistoricalBar;

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
     * @return list<HistoricalBar>
     */
    public function fetchHistory(string $symbol, int $days): array;
}
