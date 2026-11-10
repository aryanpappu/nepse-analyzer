<?php

declare(strict_types=1);

namespace App\Services\Scraper\Contracts;

use App\Services\Scraper\FundamentalRow;

/**
 * A crawlable source of per-company fundamentals.
 *
 * Split out of PriceSource because the richest fundamentals feed — ShareSansar's
 * quarterly report endpoint — publishes no price table at all. Forcing it to
 * implement fetchDailyPrices() would have meant a throwing stub in the failover
 * chain, so the price contract now extends this one instead: every PriceSource
 * is still a FundamentalsSource, but not every FundamentalsSource has to pretend
 * it can serve prices.
 *
 * Same rule as PriceSource: implementations must not touch the database, and
 * must throw ScrapeException rather than return half-parsed data.
 */
interface FundamentalsSource
{
    /** Stable key used in config and stored on each persisted row. */
    public function key(): string;

    /** Human readable name for logs and the admin UI. */
    public function label(): string;

    /**
     * Whether this source can currently crawl per-company fundamentals.
     *
     * Kept as a runtime flag rather than a marker interface because a source can
     * support fundamentals in principle but be disabled by configuration.
     */
    public function supportsFundamentals(): bool;

    /**
     * Fundamentals for a single scrip, or null when the page has no usable data.
     *
     * $sectorHint carries the sector we already believe the scrip belongs to.
     * ShareSansar's quarterly-report endpoint will not answer without it — each
     * regulator-defined sector files on a different template and the endpoint
     * picks the template from this value — and passing what the caller already
     * knows is cheaper than making the source re-derive it. It stays a *hint*:
     * sources that do not need it ignore it, and a source that needs it must
     * still cope with it being absent or stale.
     */
    public function fetchFundamentals(string $symbol, ?string $sectorHint = null): ?FundamentalRow;
}
