<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use Illuminate\Support\Carbon;

/**
 * A parsed set of index readings together with the session they belong to.
 *
 * Mirrors {@see PriceSnapshot} for the same reason: the live index widget
 * publishes whatever the last completed (or currently open) session is,
 * regardless of the local clock, so the session date has to travel with the
 * rows instead of being assumed.
 */
final readonly class IndexSnapshot
{
    /**
     * @param  list<IndexRow>  $rows
     * @param  bool  $dateIsAuthoritative  True when the date was read from the
     *                                     page itself.
     */
    public function __construct(
        public ?Carbon $tradeDate,
        public array $rows,
        public bool $dateIsAuthoritative = false,
    ) {}
}
