<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use Illuminate\Support\Carbon;

/**
 * One dated corporate event, normalised across four differently shaped feeds.
 *
 * Every field except the symbol, type and key is nullable, and that is the
 * point: a proposed dividend has a book close and a payout split but no
 * opening date, an upcoming IPO has a SEBON approval date but no opening date
 * *yet*, and an AGM has a venue and an agenda that no issue has. Absent is
 * meaningfully different from zero throughout — an issue with a null
 * `closesOn` is still open, not closed today.
 *
 * Deliberately absent: the feeds' own `status` / `bkstatus` flags. Those are
 * computed server side against ShareSansar's clock ("[Closed]", -1 upcoming,
 * 0 today, 1 done) and would be a lie the moment they were stored — a row
 * written as "upcoming" stays "upcoming" in our table forever. The dates are
 * kept and every consumer compares them itself.
 */
final readonly class CorporateActionRow
{
    /**
     * @param  string  $externalKey  Stable identity for this row within its
     *                               feed, so a re-crawl updates rather than
     *                               duplicates. Only the source knows what
     *                               identifies a row, so only the source
     *                               builds this.
     * @param  ?Carbon  $eventOn  The event's own date: an AGM's meeting date, an
     *                            issue's opening date.
     * @param  array<string, mixed>  $raw  The feed record verbatim, so a mapping
     *                                     gap is recoverable without re-crawling.
     */
    public function __construct(
        public string $symbol,
        public string $type,
        public string $externalKey,
        public string $feed,
        public ?Carbon $bookCloseOn = null,
        public ?Carbon $eventOn = null,
        public ?Carbon $closesOn = null,
        public ?Carbon $finalOn = null,
        public ?Carbon $listingOn = null,
        public ?Carbon $announcedOn = null,
        public ?Carbon $distributionOn = null,
        public ?Carbon $approvedOn = null,
        public ?string $ratio = null,
        public ?float $units = null,
        public ?float $issuePrice = null,
        public ?float $cashDividendPercent = null,
        public ?float $bonusPercent = null,
        public ?float $totalDividendPercent = null,
        public ?string $fiscalPeriod = null,
        public ?string $issueManager = null,
        public ?string $title = null,
        public ?string $venue = null,
        public ?string $agenda = null,
        public array $raw = [],
    ) {}

    /**
     * Every date this row carries, keyed by field name.
     *
     * @return array<string, Carbon>
     */
    public function dates(): array
    {
        return array_filter([
            'book_close_on' => $this->bookCloseOn,
            'event_on' => $this->eventOn,
            'closes_on' => $this->closesOn,
            'final_on' => $this->finalOn,
            'listing_on' => $this->listingOn,
            'announced_on' => $this->announcedOn,
            'distribution_on' => $this->distributionOn,
            'approved_on' => $this->approvedOn,
        ], static fn (?Carbon $date): bool => $date !== null);
    }

    /**
     * A calendar entry with no date is not a calendar entry. This is the one
     * thing every row must have, whatever its type.
     */
    public function hasAnyDate(): bool
    {
        return $this->dates() !== [];
    }

    /**
     * The same row with the named secondary dates dropped.
     *
     * Used to repair a record whose auxiliary dates contradict its timeline
     * without discarding the record. Only the four secondary fields can be
     * dropped: `event_on`, `closes_on` and `book_close_on` are what the
     * calendar is *for*, so a row whose primary timeline is broken is rejected
     * rather than quietly trimmed down to something that looks fine.
     */
    public function withoutDates(string ...$fields): self
    {
        $drop = static fn (string $field, ?Carbon $current): ?Carbon => in_array($field, $fields, true) ? null : $current;

        return new self(
            symbol: $this->symbol,
            type: $this->type,
            externalKey: $this->externalKey,
            feed: $this->feed,
            bookCloseOn: $this->bookCloseOn,
            eventOn: $this->eventOn,
            closesOn: $this->closesOn,
            finalOn: $drop('final_on', $this->finalOn),
            listingOn: $drop('listing_on', $this->listingOn),
            announcedOn: $drop('announced_on', $this->announcedOn),
            distributionOn: $drop('distribution_on', $this->distributionOn),
            approvedOn: $drop('approved_on', $this->approvedOn),
            ratio: $this->ratio,
            units: $this->units,
            issuePrice: $this->issuePrice,
            cashDividendPercent: $this->cashDividendPercent,
            bonusPercent: $this->bonusPercent,
            totalDividendPercent: $this->totalDividendPercent,
            fiscalPeriod: $this->fiscalPeriod,
            issueManager: $this->issueManager,
            title: $this->title,
            venue: $this->venue,
            agenda: $this->agenda,
            raw: $this->raw,
        );
    }
}
