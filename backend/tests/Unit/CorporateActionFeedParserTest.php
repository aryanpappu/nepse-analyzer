<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ScrapeException;
use App\Services\Scraper\CorporateActionFeedParser;
use App\Services\Scraper\CorporateActionRow;
use App\Services\Scraper\CorporateActionType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Asserted against real captured payloads, not hand-written JSON, for the same
 * reason the quarterly-report parser is: the difficulties these feeds present —
 * symbols wrapped in anchor markup, book closes carrying a "[Closed]"
 * annotation, absent dates spelled `""` in one feed and `null` in another,
 * whole records with no date at all — only show up in the real thing.
 *
 * Fixtures live in tests/Fixtures/Scraper and were captured from the live
 * DataTables endpoints. Re-capture them if a feed changes; do not hand-edit
 * them, or the tests stop describing reality.
 */
final class CorporateActionFeedParserTest extends TestCase
{
    private function fixture(string $name): string
    {
        $path = __DIR__.'/../Fixtures/Scraper/corporate-'.$name.'.json';

        $this->assertFileExists($path, "Missing fixture for {$name}.");

        return (string) file_get_contents($path);
    }

    private function parser(): CorporateActionFeedParser
    {
        return new CorporateActionFeedParser;
    }

    /**
     * @param  list<CorporateActionRow>  $rows
     */
    private function bySymbol(array $rows, string $symbol): CorporateActionRow
    {
        foreach ($rows as $row) {
            if ($row->symbol === $symbol) {
                return $row;
            }
        }

        $this->fail("No parsed row for {$symbol}.");
    }

    // ------------------------------------------------------------------
    // Proposed dividends — the feed that carries the book closes
    // ------------------------------------------------------------------

    #[Test]
    public function it_reads_a_proposed_dividend_with_its_book_close(): void
    {
        $result = $this->parser()->parseProposedDividends($this->fixture('proposed-dividend'));
        $row = $this->bySymbol($result['rows'], 'UPCL');

        $this->assertSame(CorporateActionType::DIVIDEND, $row->type);

        // The symbol arrives as "<a href='...'>UPCL</a>" and the book close as
        // "2025-09-24 [Closed]" — both must survive the annotation intact.
        $this->assertSame('2025-09-24', $row->bookCloseOn?->toDateString());
        $this->assertSame('2025-09-15', $row->announcedOn?->toDateString());
        $this->assertSame('2026-03-30', $row->listingOn?->toDateString());

        $this->assertSame(9.0, $row->bonusPercent);
        $this->assertSame(0.4737, $row->cashDividendPercent);
        $this->assertSame(9.4737, $row->totalDividendPercent);
        $this->assertSame('2081/2082', $row->fiscalPeriod);
    }

    #[Test]
    public function the_published_date_is_read_off_the_payload(): void
    {
        // The one date on the whole calendar that describes the crawl rather
        // than an event. Everything downstream is filed under it, so it must
        // come from the feed and never from the clock.
        $result = $this->parser()->parseProposedDividends($this->fixture('proposed-dividend'));

        $this->assertSame('2026-09-09', $result['publishedOn']?->toDateString());
    }

    #[Test]
    public function an_absent_distribution_date_stays_null_rather_than_becoming_today(): void
    {
        $result = $this->parser()->parseProposedDividends($this->fixture('proposed-dividend'));
        $row = $this->bySymbol($result['rows'], 'UPCL');

        $this->assertNull($row->distributionOn);
    }

    #[Test]
    public function a_dividend_row_is_titled_from_its_own_split(): void
    {
        $result = $this->parser()->parseProposedDividends($this->fixture('proposed-dividend'));
        $row = $this->bySymbol($result['rows'], 'UPCL');

        $this->assertSame('0.4737% cash + 9% bonus dividend', $row->title);
    }

    // ------------------------------------------------------------------
    // General meetings
    // ------------------------------------------------------------------

    #[Test]
    public function it_reads_a_meeting_with_its_book_close_venue_and_agenda(): void
    {
        $rows = $this->parser()->parseMeetings($this->fixture('agm-upcoming'));
        $row = $this->bySymbol($rows, 'DIVINE');

        $this->assertSame(CorporateActionType::AGM, $row->type);
        $this->assertSame('2nd AGM', $row->title);
        $this->assertSame('2026-09-11', $row->bookCloseOn?->toDateString());
        $this->assertSame('2026-09-22', $row->eventOn?->toDateString());

        // Venue and agenda arrive as <p>-wrapped prose. The agenda is where a
        // bonus is announced before any structured field carries it, so it is
        // kept whole.
        $this->assertSame("Company's Factory Premises, Bharatpur-16, Chitwan (11:00 AM)", $row->venue);
        $this->assertStringContainsString('1.25% Bonus Shares', (string) $row->agenda);
        $this->assertStringNotContainsString('<p>', (string) $row->agenda);
    }

    #[Test]
    public function a_meeting_is_keyed_by_company_and_date_because_the_feed_has_no_row_id(): void
    {
        $rows = $this->parser()->parseMeetings($this->fixture('agm-upcoming'));
        $row = $this->bySymbol($rows, 'DIVINE');

        $this->assertSame('agm-list:DIVINE:2026-09-22', $row->externalKey);
    }

    #[Test]
    public function every_parsed_meeting_carries_a_date(): void
    {
        $rows = $this->parser()->parseMeetings($this->fixture('agm-upcoming'));

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertTrue($row->hasAnyDate(), "{$row->symbol} produced a meeting with no date at all.");
        }
    }

    // ------------------------------------------------------------------
    // Issues
    // ------------------------------------------------------------------

    #[Test]
    public function it_reads_a_rights_issue_and_keeps_the_ratio_as_published(): void
    {
        $rows = $this->parser()->parseIssues($this->fixture('existing-issue-rights'), 3);
        $row = $this->bySymbol($rows, 'UHEWA');

        $this->assertSame(CorporateActionType::RIGHTS, $row->type);

        // "1:1" and "2:1" are different offers and the left side is not always
        // 1, so the ratio is never collapsed to a float.
        $this->assertSame('1:1', $row->ratio);
        $this->assertSame(5_750_000.0, $row->units);
        $this->assertSame(100.0, $row->issuePrice);
    }

    #[Test]
    public function an_issue_the_feed_never_dated_produces_a_row_with_no_dates(): void
    {
        // Real records in this feed carry "" for every date. The parser does not
        // invent one; the validator is what refuses to persist it, so the
        // rejection is counted rather than silently dropped here.
        $rows = $this->parser()->parseIssues($this->fixture('existing-issue-rights'), 3);
        $row = $this->bySymbol($rows, 'UHEWA');

        $this->assertNull($row->eventOn);
        $this->assertNull($row->closesOn);
        $this->assertFalse($row->hasAnyDate());
    }

    #[Test]
    public function an_upcoming_issue_has_an_approval_date_but_no_opening_date(): void
    {
        $rows = $this->parser()->parseUpcomingIssues($this->fixture('upcoming-issue-ipo'), 1);
        $row = $this->bySymbol($rows, 'MADHYAPUR');

        $this->assertSame(CorporateActionType::IPO, $row->type);
        $this->assertSame('2026-08-18', $row->approvedOn?->toDateString());
        $this->assertSame('2026-08-07', $row->announcedOn?->toDateString());

        // The whole distinction from /existing-issues. Guessing when an approved
        // issue will open is exactly the prediction this codebase refuses.
        $this->assertNull($row->eventOn);
        $this->assertSame('Muktinath Capital Limited', $row->issueManager);
    }

    #[Test]
    public function the_feed_type_decides_the_instrument_because_no_record_says(): void
    {
        $json = $this->fixture('upcoming-issue-ipo');

        $this->assertSame(
            CorporateActionType::IPO,
            $this->parser()->parseUpcomingIssues($json, 1)[0]->type,
        );
        $this->assertSame(
            CorporateActionType::RIGHTS,
            $this->parser()->parseUpcomingIssues($json, 3)[0]->type,
        );
    }

    #[Test]
    public function an_unknown_feed_type_is_refused_rather_than_guessed(): void
    {
        $this->expectException(ScrapeException::class);

        $this->parser()->parseIssues($this->fixture('existing-issue-rights'), 99);
    }

    // ------------------------------------------------------------------
    // Payload shape
    // ------------------------------------------------------------------

    #[Test]
    public function it_reports_the_record_count_the_feed_claims(): void
    {
        // Used as a coverage check: the site says 322 rights records exist, and
        // a page that returns far fewer means the request was wrong.
        $this->assertSame(322, $this->parser()->recordsTotal($this->fixture('existing-issue-rights')));
    }

    #[Test]
    public function a_body_that_is_not_a_datatables_payload_says_the_markup_changed(): void
    {
        // The failure this must never do quietly: returning an empty calendar
        // when the endpoint has started answering with something else entirely.
        $this->expectException(ScrapeException::class);
        $this->expectExceptionMessageMatches('/no "data" key/');

        $this->parser()->parseMeetings('<!DOCTYPE html><html><body>Sign in</body></html>');
    }

    #[Test]
    public function an_empty_but_well_formed_payload_is_not_an_error(): void
    {
        // A feed legitimately having nothing to show is different from a broken
        // feed, and only the caller can tell whether every feed came back empty.
        $this->assertSame([], $this->parser()->parseMeetings('{"draw":1,"recordsTotal":0,"data":[]}'));
    }

    #[Test]
    public function datatables_presentation_fields_are_kept_out_of_raw(): void
    {
        $rows = $this->parser()->parseUpcomingIssues($this->fixture('upcoming-issue-ipo'), 1);

        $this->assertArrayNotHasKey('DT_Row_Index', $rows[0]->raw);
        $this->assertArrayHasKey('total_units', $rows[0]->raw);
    }
}
