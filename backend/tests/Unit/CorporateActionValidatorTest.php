<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scraper\CorporateActionFeedParser;
use App\Services\Scraper\CorporateActionRow;
use App\Services\Scraper\CorporateActionType;
use App\Services\Scraper\CorporateActionValidator;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The calendar's guardrail. A book close read out of the wrong column becomes
 * an alert telling someone to buy before a date that means nothing, which is
 * worse than having no calendar — so the same standard the price scraper holds
 * applies here: reject a row rather than persist a plausible-looking lie.
 *
 * The app is booted only so config() resolves the tolerances; no query is run.
 */
final class CorporateActionValidatorTest extends TestCase
{
    private function validator(): CorporateActionValidator
    {
        return new CorporateActionValidator;
    }

    private function published(string $date = '2026-09-09'): Carbon
    {
        return Carbon::parse($date)->startOfDay();
    }

    /**
     * A dividend row that passes, which each test then breaks in one way.
     */
    private function dividend(
        ?Carbon $bookCloseOn = null,
        ?float $totalDividendPercent = null,
    ): CorporateActionRow {
        return new CorporateActionRow(
            symbol: 'UPCL',
            type: CorporateActionType::DIVIDEND,
            externalKey: 'proposed-dividend:610',
            feed: CorporateActionFeedParser::FEED_PROPOSED_DIVIDEND,
            bookCloseOn: $bookCloseOn ?? Carbon::parse('2025-09-24'),
            listingOn: Carbon::parse('2026-03-30'),
            announcedOn: Carbon::parse('2025-09-15'),
            cashDividendPercent: 0.4737,
            bonusPercent: 9.0,
            totalDividendPercent: $totalDividendPercent ?? 9.4737,
        );
    }

    #[Test]
    public function a_real_dividend_row_passes(): void
    {
        $verdict = $this->validator()->validate($this->dividend(), $this->published());

        $this->assertTrue($verdict['valid'], $verdict['reason'] ?? '');
    }

    #[Test]
    public function a_row_with_no_date_at_all_is_not_a_calendar_entry(): void
    {
        // The rights feed publishes plenty of these: old issues whose dates it
        // never recorded. They parse cleanly and are still worthless.
        $row = new CorporateActionRow(
            symbol: 'UHEWA',
            type: CorporateActionType::RIGHTS,
            externalKey: 'existing-issues:3:UHEWA:undated:5750000',
            feed: CorporateActionFeedParser::FEED_EXISTING_ISSUES,
            ratio: '1:1',
            units: 5_750_000.0,
        );

        $verdict = $this->validator()->validate($row, $this->published());

        $this->assertFalse($verdict['valid']);
        $this->assertStringContainsString('no date on the record', $verdict['reason']);
    }

    #[Test]
    public function a_total_that_disagrees_with_its_own_parts_is_a_moved_column(): void
    {
        // The feed publishes cash, bonus and total, so the total is arithmetic
        // we can check rather than a figure we have to trust.
        $verdict = $this->validator()->validate(
            $this->dividend(totalDividendPercent: 4.7),
            $this->published(),
        );

        $this->assertFalse($verdict['valid']);
        $this->assertStringContainsString('does not match cash', $verdict['reason']);
    }

    #[Test]
    public function rounding_in_the_published_parts_is_tolerated(): void
    {
        // Each part is rounded to four decimals independently at the source, so
        // an exact-equality check would reject correct rows.
        $verdict = $this->validator()->validate(
            $this->dividend(totalDividendPercent: 9.4738),
            $this->published(),
        );

        $this->assertTrue($verdict['valid'], $verdict['reason'] ?? '');
    }

    #[Test]
    public function an_issue_that_closes_before_it_opens_has_swapped_columns(): void
    {
        $row = new CorporateActionRow(
            symbol: 'NICA',
            type: CorporateActionType::RIGHTS,
            externalKey: 'existing-issues:3:NICA:2026-07-08:1',
            feed: CorporateActionFeedParser::FEED_EXISTING_ISSUES,
            eventOn: Carbon::parse('2026-07-08'),
            closesOn: Carbon::parse('2026-06-30'),
        );

        $verdict = $this->validator()->validate($row, $this->published());

        $this->assertFalse($verdict['valid']);
        $this->assertStringContainsString('probably swapped', $verdict['reason']);
    }

    #[Test]
    public function a_meeting_before_its_own_book_close_is_still_accepted(): void
    {
        // Deliberately not an ordering rule: an issuer can move a meeting to
        // before a previously announced closure, and rejecting that would drop
        // real rows. Only pairs whose order is genuinely fixed are checked.
        $row = new CorporateActionRow(
            symbol: 'NORVIC',
            type: CorporateActionType::AGM,
            externalKey: 'agm-list:NORVIC:2026-08-01',
            feed: CorporateActionFeedParser::FEED_AGM_LIST,
            bookCloseOn: Carbon::parse('2026-08-16'),
            eventOn: Carbon::parse('2026-08-01'),
        );

        $this->assertTrue($this->validator()->validate($row, $this->published())['valid']);
    }

    #[Test]
    public function zero_units_means_the_field_was_not_what_we_thought(): void
    {
        $row = new CorporateActionRow(
            symbol: 'BENI',
            type: CorporateActionType::IPO,
            externalKey: 'existing-issues:1:BENI:2026-09-07:0',
            feed: CorporateActionFeedParser::FEED_EXISTING_ISSUES,
            eventOn: Carbon::parse('2026-09-07'),
            units: 0.0,
        );

        $verdict = $this->validator()->validate($row, $this->published());

        $this->assertFalse($verdict['valid']);
        $this->assertStringContainsString('total units is zero', $verdict['reason']);
    }

    #[Test]
    public function the_horizon_is_measured_against_the_sources_own_published_date(): void
    {
        // A lone far-future book close, so the horizon is the only rule in play.
        $row = new CorporateActionRow(
            symbol: 'UPCL',
            type: CorporateActionType::DIVIDEND,
            externalKey: 'proposed-dividend:610',
            feed: CorporateActionFeedParser::FEED_PROPOSED_DIVIDEND,
            bookCloseOn: Carbon::parse('2045-01-01'),
        );

        $verdict = $this->validator()->validate($row, $this->published());

        $this->assertFalse($verdict['valid']);
        $this->assertStringContainsString("after the source's own published date", $verdict['reason']);

        // ...and with no published date the check is skipped entirely rather
        // than silently made against the machine's clock.
        $this->assertTrue($this->validator()->validate($row, null)['valid']);
    }

    #[Test]
    public function an_old_but_real_issue_survives_the_horizon(): void
    {
        // These feeds are a full archive — rights issues go back to 2011 — so
        // the past side has to be generous or the calendar loses its history.
        $row = new CorporateActionRow(
            symbol: 'SANIMA',
            type: CorporateActionType::RIGHTS,
            externalKey: 'existing-issues:3:SANIMA:2011-06-01:1',
            feed: CorporateActionFeedParser::FEED_EXISTING_ISSUES,
            eventOn: Carbon::parse('2011-06-01'),
        );

        $this->assertTrue($this->validator()->validate($row, $this->published())['valid']);
    }

    #[Test]
    public function an_unrecognised_event_type_is_refused(): void
    {
        $row = new CorporateActionRow(
            symbol: 'NABIL',
            type: 'merger',
            externalKey: 'x',
            feed: 'x',
            eventOn: Carbon::parse('2026-09-01'),
        );

        $verdict = $this->validator()->validate($row, $this->published());

        $this->assertFalse($verdict['valid']);
        $this->assertStringContainsString('unknown event type', $verdict['reason']);
    }

    // ------------------------------------------------------------------
    // Repair
    // ------------------------------------------------------------------

    #[Test]
    public function a_contradictory_final_date_is_dropped_and_the_issue_is_kept(): void
    {
        // ShareSansar's issue archive carries a few hundred 2011-2014 rows whose
        // final_date precedes their closing_date. Discarding those would lose a
        // real IPO's opening and closing dates over a field almost nothing reads.
        $row = new CorporateActionRow(
            symbol: 'NABIL',
            type: CorporateActionType::IPO,
            externalKey: 'existing-issues:1:NABIL:2014-04-01:1',
            feed: CorporateActionFeedParser::FEED_EXISTING_ISSUES,
            eventOn: Carbon::parse('2014-04-01'),
            closesOn: Carbon::parse('2014-06-01'),
            finalOn: Carbon::parse('2014-04-10'),
        );

        $repair = $this->validator()->repair($row);

        $this->assertTrue($repair['repaired']);
        $this->assertNull($repair['row']->finalOn);

        // Everything that matters survives, and the repaired row then passes.
        $this->assertSame('2014-04-01', $repair['row']->eventOn?->toDateString());
        $this->assertSame('2014-06-01', $repair['row']->closesOn?->toDateString());
        $this->assertTrue($this->validator()->validate($repair['row'], $this->published())['valid']);
        $this->assertNotEmpty($repair['reasons']);
    }

    #[Test]
    public function a_broken_primary_timeline_is_rejected_rather_than_repaired(): void
    {
        // An issue closing before it opens is the calendar's actual content
        // being wrong. Trimming it into something that looks fine would hide
        // exactly the swapped-column bug the validator exists to catch.
        $row = new CorporateActionRow(
            symbol: 'NABIL',
            type: CorporateActionType::IPO,
            externalKey: 'existing-issues:1:NABIL:2014-05-04:1',
            feed: CorporateActionFeedParser::FEED_EXISTING_ISSUES,
            eventOn: Carbon::parse('2014-05-04'),
            closesOn: Carbon::parse('2013-06-07'),
        );

        $this->assertFalse($this->validator()->repair($row)['repaired']);
        $this->assertFalse($this->validator()->validate($row, $this->published())['valid']);
    }

    #[Test]
    public function repair_never_strips_a_row_down_to_no_dates_at_all(): void
    {
        // Dropping the only date a row had would turn a rejectable row into a
        // silently "repaired" empty one.
        $row = new CorporateActionRow(
            symbol: 'NABIL',
            type: CorporateActionType::DIVIDEND,
            externalKey: 'proposed-dividend:1',
            feed: CorporateActionFeedParser::FEED_PROPOSED_DIVIDEND,
            bookCloseOn: Carbon::parse('2026-05-01'),
            listingOn: Carbon::parse('2026-01-01'),
        );

        $repair = $this->validator()->repair($row);

        $this->assertTrue($repair['repaired']);
        $this->assertTrue($repair['row']->hasAnyDate());
        $this->assertSame('2026-05-01', $repair['row']->bookCloseOn?->toDateString());
    }

    #[Test]
    public function a_consistent_row_is_left_untouched(): void
    {
        $repair = $this->validator()->repair($this->dividend());

        $this->assertFalse($repair['repaired']);
        $this->assertSame([], $repair['reasons']);
        $this->assertSame('2026-03-30', $repair['row']->listingOn?->toDateString());
    }

    #[Test]
    public function every_row_parsed_from_a_real_payload_is_either_valid_or_refused_for_a_stated_reason(): void
    {
        // A sweep over the captured fixtures: nothing may pass validation by
        // accident, and nothing may be rejected without a reason to record.
        $parser = new CorporateActionFeedParser;
        $dir = __DIR__.'/../Fixtures/Scraper/';

        $rows = array_merge(
            $parser->parseProposedDividends((string) file_get_contents($dir.'corporate-proposed-dividend.json'))['rows'],
            $parser->parseMeetings((string) file_get_contents($dir.'corporate-agm-upcoming.json')),
            $parser->parseIssues((string) file_get_contents($dir.'corporate-existing-issue-rights.json'), 3),
            $parser->parseUpcomingIssues((string) file_get_contents($dir.'corporate-upcoming-issue-ipo.json'), 1),
        );

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $repair = $this->validator()->repair($row);
            $verdict = $this->validator()->validate($repair['row'], $this->published());

            if ($verdict['valid'] !== true) {
                $this->assertNotEmpty($verdict['reason'] ?? '', "{$row->symbol} was rejected with no reason.");
            }

            if ($repair['repaired']) {
                $this->assertNotEmpty($repair['reasons'], "{$row->symbol} was repaired with no reason.");
            }
        }
    }
}
