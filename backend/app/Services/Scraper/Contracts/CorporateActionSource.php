<?php

declare(strict_types=1);

namespace App\Services\Scraper\Contracts;

use App\Exceptions\ScrapeException;
use App\Services\Scraper\CorporateActionSnapshot;

/**
 * A crawlable source of the corporate calendar — book closes, general
 * meetings, dividend proposals, rights and public issues.
 *
 * Its own contract rather than a method bolted onto {@see PriceSource} for the
 * same reason {@see FundamentalsSource} was split out: the site that publishes
 * this publishes no price table alongside it, and the feeds are not a daily
 * session but a forward-looking list. Forcing a price source to implement it
 * would mean a throwing stub in the failover chain.
 *
 * Same rules as every other source: implementations must not touch the
 * database, and must throw ScrapeException rather than return half-parsed
 * data, so the caller can record a failed run instead of persisting rubbish.
 */
interface CorporateActionSource
{
    /** Stable key used in config and stored on each persisted row. */
    public function key(): string;

    /** Human readable name for logs and the admin UI. */
    public function label(): string;

    /**
     * Every corporate event the source currently publishes.
     *
     * Returns the date the *source* says it published, not the date requested
     * or the date today — these feeds accept no date filter at all and simply
     * render current state, so any date must come off the payload.
     *
     * A feed that fails is reported in the snapshot's `feedErrors` rather than
     * aborting the crawl: the calendar is four independent feeds, and losing
     * the IPO list is no reason to lose every book close.
     *
     * @throws ScrapeException When the source cannot be reached
     *                         or bootstrapped at all.
     */
    public function fetchCorporateActions(): CorporateActionSnapshot;
}
