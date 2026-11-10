<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Exceptions\ScrapeException;
use App\Support\Numeric;
use Illuminate\Support\Carbon;

/**
 * Turns ShareSansar's four corporate-calendar DataTables payloads into
 * {@see CorporateActionRow}s.
 *
 * These tabs are rendered client side from DataTables server-side JSON, so
 * unlike the price and quarterly-report pages there is no HTML to parse: the
 * same URL as the page returns `{draw, recordsTotal, recordsFiltered, data:
 * [...]}` when asked as an AJAX request. That is strictly better than scraping
 * a rendered table — the dates arrive as `YYYY-MM-DD` rather than as whatever
 * the table's formatter chose — but it is still an undocumented private feed,
 * so every field is treated as optional and nothing is assumed about ordering.
 *
 * Four shapes, and they genuinely differ; there is no single record schema to
 * collapse them into upstream:
 *
 * | Feed                | Carries                                              |
 * |---------------------|------------------------------------------------------|
 * | `/proposed-dividend`| cash %, bonus %, book close, bonus listing, FY, and a `published_date` |
 * | `/agm-list`         | meeting date, book close, venue, full agenda text    |
 * | `/existing-issues`  | opening / closing / final / listing dates, ratio, units, price |
 * | `/upcoming-issue`   | SEBON approval and application dates — **no opening date yet** |
 *
 * Two source quirks handled here:
 *
 * - **Symbols arrive as anchor markup.** Every symbol and company name is
 *   `<a href='...'>NABIL</a>`, so tags are stripped rather than the value
 *   trusted. The same is true of the AGM venue and agenda, which are `<p>`
 *   wrapped prose.
 * - **Dates arrive with a status annotation.** `bookclose_date` reads
 *   `"2025-09-24 [Closed]"`, and an absent date is `""` in some feeds and
 *   `null` in others. Only the leading ISO date is read, and everything else
 *   becomes null rather than today.
 *
 * Pure and database-free, which is what lets the whole mapping be pinned to
 * real captured payloads in tests/Fixtures/Scraper.
 */
final class CorporateActionFeedParser
{
    public const FEED_PROPOSED_DIVIDEND = 'proposed-dividend';

    public const FEED_AGM_LIST = 'agm-list';

    public const FEED_EXISTING_ISSUES = 'existing-issues';

    public const FEED_UPCOMING_ISSUE = 'upcoming-issue';

    /**
     * Proposed cash and bonus dividends, each with its book close.
     *
     * The richest single feed, and the one that answers "what closes this
     * week": 169 live records at the time of writing, every one carrying a
     * book-close date.
     *
     * @return array{rows: list<CorporateActionRow>, publishedOn: ?Carbon}
     *
     * @throws ScrapeException
     */
    public function parseProposedDividends(string $json): array
    {
        $records = $this->records($json, self::FEED_PROPOSED_DIVIDEND);
        $rows = [];
        $publishedOn = null;

        foreach ($records as $record) {
            $symbol = $this->symbol($record['symbol'] ?? null);

            if ($symbol === null) {
                continue;
            }

            // The feed stamps every record with the date it is publishing. It is
            // the only date on the whole calendar that describes the crawl
            // rather than an event, so it is what the run is filed under.
            $publishedOn ??= $this->date($record['published_date'] ?? null);

            $id = $record['id'] ?? null;

            $rows[] = new CorporateActionRow(
                symbol: $symbol,
                type: CorporateActionType::DIVIDEND,
                externalKey: self::FEED_PROPOSED_DIVIDEND.':'.($id ?? $symbol),
                feed: self::FEED_PROPOSED_DIVIDEND,
                bookCloseOn: $this->date($record['bookclose_date'] ?? null),
                listingOn: $this->date($record['bonus_listing_date'] ?? null),
                announcedOn: $this->date($record['announcement_date'] ?? null),
                distributionOn: $this->date($record['distribution_date'] ?? null),
                cashDividendPercent: $this->number($record['cash_dividend'] ?? null),
                bonusPercent: $this->number($record['bonus_share'] ?? null),
                totalDividendPercent: $this->number($record['total_dividend'] ?? null),
                fiscalPeriod: $this->text($record['year'] ?? null),
                title: $this->payoutTitle($record),
                raw: $this->raw($record),
            );
        }

        return ['rows' => $rows, 'publishedOn' => $publishedOn];
    }

    /**
     * General meetings, with the book close that decides who may attend.
     *
     * @return list<CorporateActionRow>
     *
     * @throws ScrapeException
     */
    public function parseMeetings(string $json): array
    {
        $records = $this->records($json, self::FEED_AGM_LIST);
        $rows = [];

        foreach ($records as $record) {
            $symbol = $this->symbol($record['symbol'] ?? null);

            if ($symbol === null) {
                continue;
            }

            $meetingOn = $this->date($record['meeting_date'] ?? null);
            $bookCloseOn = $this->date($record['bookclose_date'] ?? null);
            $label = $this->text($record['agm'] ?? null);

            // The feed publishes no row id, so identity is the company and the
            // date the meeting is called for — which is what a re-crawl of the
            // same meeting will still say. Falling back to the book close keeps
            // a row whose meeting date has not been announced yet distinct.
            $key = $meetingOn?->toDateString() ?? $bookCloseOn?->toDateString() ?? 'undated';

            $rows[] = new CorporateActionRow(
                symbol: $symbol,
                type: CorporateActionType::meetingFrom($label),
                externalKey: self::FEED_AGM_LIST.':'.$symbol.':'.$key,
                feed: self::FEED_AGM_LIST,
                bookCloseOn: $bookCloseOn,
                eventOn: $meetingOn,
                title: $label,
                venue: $this->text($record['venue_time'] ?? null),
                agenda: $this->text($record['agenda'] ?? null),
                raw: $this->raw($record),
            );
        }

        return $rows;
    }

    /**
     * Rights and public issues that have opened, closed or been withdrawn.
     *
     * @param  int  $feedType  ShareSansar's numeric `type` parameter; decides
     *                         which instrument the payload describes, because
     *                         nothing in the records themselves says.
     * @return list<CorporateActionRow>
     *
     * @throws ScrapeException
     */
    public function parseIssues(string $json, int $feedType): array
    {
        $type = CorporateActionType::issueFeedTypes()[$feedType] ?? null;

        if ($type === null) {
            throw ScrapeException::markupChanged(
                self::FEED_EXISTING_ISSUES,
                "feed type {$feedType} is not one of the issue types the page publishes",
            );
        }

        $records = $this->records($json, self::FEED_EXISTING_ISSUES);
        $rows = [];

        foreach ($records as $record) {
            $symbol = $this->symbol($this->nested($record, 'company', 'symbol'));

            if ($symbol === null) {
                continue;
            }

            $openedOn = $this->date($record['opening_date'] ?? null);
            $units = $this->number($record['total_units'] ?? null);

            // No row id here either. The opening date identifies an issue, and
            // the unit count separates two issues by the same company that the
            // feed has left undated — which it does for older records.
            $rows[] = new CorporateActionRow(
                symbol: $symbol,
                type: $type,
                externalKey: sprintf(
                    '%s:%d:%s:%s:%s',
                    self::FEED_EXISTING_ISSUES,
                    $feedType,
                    $symbol,
                    $openedOn?->toDateString() ?? 'undated',
                    $units === null ? 'nounits' : (string) $units,
                ),
                feed: self::FEED_EXISTING_ISSUES,
                eventOn: $openedOn,
                closesOn: $this->date($record['closing_date'] ?? null),
                finalOn: $this->date($record['final_date'] ?? null),
                listingOn: $this->date($record['listing_date'] ?? null),
                ratio: $this->ratio($record['ratio_value'] ?? null),
                units: $units,
                issuePrice: $this->number($record['issue_price'] ?? null),
                issueManager: $this->text($record['issue_manager'] ?? null),
                raw: $this->raw($record),
            );
        }

        return $rows;
    }

    /**
     * Issues approved by SEBON but not yet open for application.
     *
     * These have no opening date at all — that is the whole distinction from
     * /existing-issues, and it is why `eventOn` stays null here rather than
     * being filled with the approval date. Guessing when an approved issue
     * will open is precisely the prediction this codebase does not make.
     *
     * @return list<CorporateActionRow>
     *
     * @throws ScrapeException
     */
    public function parseUpcomingIssues(string $json, int $feedType): array
    {
        $type = CorporateActionType::issueFeedTypes()[$feedType] ?? null;

        if ($type === null) {
            throw ScrapeException::markupChanged(
                self::FEED_UPCOMING_ISSUE,
                "feed type {$feedType} is not one of the issue types the page publishes",
            );
        }

        $records = $this->records($json, self::FEED_UPCOMING_ISSUE);
        $rows = [];

        foreach ($records as $record) {
            $symbol = $this->symbol($this->nested($record, 'company', 'symbol'));

            if ($symbol === null) {
                continue;
            }

            $appliedOn = $this->date($record['application_date'] ?? null);

            $rows[] = new CorporateActionRow(
                symbol: $symbol,
                type: $type,
                externalKey: sprintf(
                    '%s:%d:%s:%s',
                    self::FEED_UPCOMING_ISSUE,
                    $feedType,
                    $symbol,
                    $appliedOn?->toDateString() ?? 'undated',
                ),
                feed: self::FEED_UPCOMING_ISSUE,
                announcedOn: $appliedOn,
                approvedOn: $this->date($record['date_sebon'] ?? null),
                ratio: $this->ratio($record['ratio_value'] ?? null),
                units: $this->number($record['total_units'] ?? null),
                issueManager: $this->text($record['issue_manager'] ?? null),
                raw: $this->raw($record),
            );
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // Shared field handling
    // ------------------------------------------------------------------

    /**
     * The `data` array of a DataTables payload.
     *
     * An empty `data` is not an error — a feed legitimately has nothing to show
     * — but a body that is not a DataTables payload at all means the endpoint
     * changed, and must say so rather than surface as an empty calendar.
     *
     * @return list<array<string, mixed>>
     *
     * @throws ScrapeException
     */
    private function records(string $json, string $feed): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true);

        if (! is_array($decoded) || ! array_key_exists('data', $decoded)) {
            throw ScrapeException::markupChanged(
                $feed,
                'response is not a DataTables payload — no "data" key',
            );
        }

        if (! is_array($decoded['data'])) {
            throw ScrapeException::markupChanged($feed, '"data" is not a list of records');
        }

        return array_values(array_filter(
            $decoded['data'],
            static fn (mixed $record): bool => is_array($record),
        ));
    }

    /**
     * Total records the feed says exist, for a coverage check against how many
     * we actually pulled. Null when the payload does not say.
     */
    public function recordsTotal(string $json): ?int
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true);

        if (! is_array($decoded) || ! isset($decoded['recordsTotal'])) {
            return null;
        }

        return is_numeric($decoded['recordsTotal']) ? (int) $decoded['recordsTotal'] : null;
    }

    /**
     * `<a href='...'>NABIL</a>` => `NABIL`.
     *
     * Returns null for an empty or implausible ticker rather than an empty
     * string, so the caller skips the record instead of persisting a row that
     * can never be joined to a stock.
     */
    private function symbol(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = strtoupper(Numeric::text(strip_tags($value)));
        $clean = preg_replace('/[^A-Z0-9\-]/', '', $clean) ?? '';

        return $clean === '' || strlen($clean) > 24 ? null : $clean;
    }

    /**
     * The leading ISO date of a value that may carry a status annotation.
     *
     * `"2025-09-24 [Closed]"` => 2025-09-24, `""` => null, `null` => null,
     * and anything that is not an ISO date => null. Deliberately strict: these
     * feeds are consistent about `YYYY-MM-DD`, and accepting looser input is
     * how a `"-"` placeholder becomes today's date.
     */
    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value)) {
            return null;
        }

        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $value, $match) !== 1) {
            return null;
        }

        if (! checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            return null;
        }

        return Carbon::create((int) $match[1], (int) $match[2], (int) $match[3])->startOfDay();
    }

    private function number(mixed $value): ?float
    {
        return Numeric::parse(is_string($value) ? $value : (is_numeric($value) ? (string) $value : null));
    }

    /**
     * Strip markup and collapse whitespace, keeping the prose.
     *
     * Venues and agendas arrive `<p>` wrapped and the agenda in particular is a
     * long comma-separated sentence that is the single most useful field on the
     * feed — it is where "20% Bonus Shares" is announced before any structured
     * field carries it — so it is kept whole rather than parsed.
     */
    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = Numeric::text(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));

        return $clean === '' || $clean === '-' ? null : $clean;
    }

    /**
     * A rights ratio, kept as published (`1:0.56621279`).
     *
     * Not converted to a float: `1:1` and `2:1` are different offers and the
     * left side is not always 1, so collapsing it to one number loses the
     * meaning. Anything not shaped like a ratio is dropped.
     */
    private function ratio(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = preg_replace('/\s+/', '', $value) ?? '';

        return preg_match('/^\d+(?:\.\d+)?:\d+(?:\.\d+)?$/', $clean) === 1 ? $clean : null;
    }

    private function nested(array $record, string $outer, string $inner): mixed
    {
        $value = $record[$outer] ?? null;

        return is_array($value) ? ($value[$inner] ?? null) : null;
    }

    /**
     * The payout as a headline, so a dividend row reads like a calendar entry
     * without the consumer recomposing it from two columns.
     */
    private function payoutTitle(array $record): ?string
    {
        $cash = $this->number($record['cash_dividend'] ?? null);
        $bonus = $this->number($record['bonus_share'] ?? null);

        $parts = [];

        if ($cash !== null && $cash > 0) {
            $parts[] = rtrim(rtrim(number_format($cash, 4, '.', ''), '0'), '.').'% cash';
        }

        if ($bonus !== null && $bonus > 0) {
            $parts[] = rtrim(rtrim(number_format($bonus, 4, '.', ''), '0'), '.').'% bonus';
        }

        return $parts === [] ? null : implode(' + ', $parts).' dividend';
    }

    /**
     * The record verbatim, minus DataTables' own presentation fields.
     *
     * `view`, `DT_Row_Index` and the anchor-wrapped duplicates are rendering
     * artefacts, not data, and keeping them would triple the stored JSON.
     *
     * @return array<string, mixed>
     */
    private function raw(array $record): array
    {
        unset($record['view'], $record['DT_Row_Index'], $record['price_range']);

        return $record;
    }
}
