<?php

declare(strict_types=1);

namespace App\Services\Scraper;

/**
 * How `stock_fundamentals.reported_on` was arrived at.
 *
 * Stored alongside the date because a date the codebase inferred and a date it
 * read must not be indistinguishable downstream. Benchmark weights as at a
 * window start are the reason `reported_on` exists at all, and a consumer that
 * needs a published boundary — rather than one interpolated to within a couple
 * of days — has to be able to ask.
 */
final class StockFundamentalPrecision
{
    /**
     * A published boundary: an annual or Q4 period whose fiscal-year end is in
     * the conversion table, a quarter end asserted in config, or a date read
     * off the filing itself.
     */
    public const EXACT = 'exact';

    /**
     * Interpolated between two known fiscal-year boundaries, or dated from a
     * fiscal year outside the conversion table. Measured against the real BS
     * calendar on this data it lands within three days.
     */
    public const APPROXIMATE = 'approximate';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::EXACT, self::APPROXIMATE];
    }
}
