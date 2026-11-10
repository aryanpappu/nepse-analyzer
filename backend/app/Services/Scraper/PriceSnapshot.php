<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use Illuminate\Support\Carbon;

/**
 * A parsed price table together with the trading session it actually belongs to.
 *
 * The session date has to travel with the rows rather than being inferred from
 * the local clock. These sites publish the *last completed* session, so on a
 * given afternoon the page may still be showing yesterday's numbers — writing
 * those under today's date would silently corrupt the price history and every
 * moving average derived from it.
 */
final readonly class PriceSnapshot
{
    /**
     * @param  list<PriceRow>  $rows
     * @param  bool  $dateIsAuthoritative  True when the date was read from the
     *                                     page itself, false when it had to be
     *                                     inferred from the market calendar.
     */
    public function __construct(
        public Carbon $tradeDate,
        public array $rows,
        public bool $dateIsAuthoritative = false,
    ) {}

    public function count(): int
    {
        return count($this->rows);
    }
}
