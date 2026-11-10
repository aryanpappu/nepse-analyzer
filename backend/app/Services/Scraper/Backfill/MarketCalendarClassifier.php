<?php

declare(strict_types=1);

namespace App\Services\Scraper\Backfill;

use App\Models\MarketCalendarDay;

/**
 * Classifies a single calendar date from what has actually been observed -
 * never from a weekday rule.
 *
 * NEPSE's trading week is not a constant (it moved from Sunday-Thursday to
 * Monday-Friday, and public holidays follow the Bikram Sambat calendar, which
 * no formula reproduces), so "is this a session" is answered by evidence, not
 * by inference from the day of the week:
 *
 *  - A bar for even one symbol proves the market traded - full stop.
 *  - Zero bars only proves a closure when the absence was actually checked
 *    for (see HistoryBackfillService for how "checked" is established, from
 *    the liquid reference basket's own fetch coverage and successful daily
 *    scrape runs) - otherwise it is indistinguishable from "nobody looked".
 *  - A failed or missing attempt is never evidence of anything.
 *  - A manual classification always wins, so a wrong verdict is correctable
 *    without reaching for a database console.
 */
final class MarketCalendarClassifier
{
    /**
     * @return array{classification: string, classified_by: ?string}
     */
    public function classify(
        bool $hasObservedBar,
        bool $wasCheckedForTrading,
        ?string $manualClassification = null,
    ): array {
        if ($manualClassification !== null) {
            return ['classification' => $manualClassification, 'classified_by' => MarketCalendarDay::CLASSIFIED_BY_MANUAL];
        }

        if ($hasObservedBar) {
            return ['classification' => MarketCalendarDay::TRADING_DAY, 'classified_by' => MarketCalendarDay::CLASSIFIED_BY_OBSERVED];
        }

        if ($wasCheckedForTrading) {
            return ['classification' => MarketCalendarDay::HOLIDAY, 'classified_by' => MarketCalendarDay::CLASSIFIED_BY_OBSERVED];
        }

        return ['classification' => MarketCalendarDay::UNKNOWN, 'classified_by' => null];
    }
}
