<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scraper\Backfill\TradingSessionCalendar;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class TradingSessionCalendarTest extends TestCase
{
    private const NEPSE_WEEKDAYS = [7, 1, 2, 3, 4]; // Sun, Mon, Tue, Wed, Thu (ISO-8601)

    private TradingSessionCalendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calendar = new TradingSessionCalendar(self::NEPSE_WEEKDAYS);
    }

    public function test_it_excludes_friday_and_saturday(): void
    {
        // 2026-09-06 is a Sunday; 2026-09-12 the following Saturday.
        $sessions = $this->calendar->sessionsBetween(
            Carbon::parse('2026-09-06'),
            Carbon::parse('2026-09-12'),
        );

        $this->assertSame([
            '2026-09-06', // Sun
            '2026-09-07', // Mon
            '2026-09-08', // Tue
            '2026-09-09', // Wed
            '2026-09-10', // Thu
        ], $sessions);

        $this->assertNotContains('2026-09-11', $sessions); // Fri
        $this->assertNotContains('2026-09-12', $sessions); // Sat
    }

    public function test_it_includes_both_endpoints_when_they_are_trading_days(): void
    {
        $sessions = $this->calendar->sessionsBetween(
            Carbon::parse('2026-09-06'),
            Carbon::parse('2026-09-06'),
        );

        $this->assertSame(['2026-09-06'], $sessions);
    }

    public function test_it_returns_empty_for_a_single_non_trading_day(): void
    {
        $sessions = $this->calendar->sessionsBetween(
            Carbon::parse('2026-09-11'), // Friday
            Carbon::parse('2026-09-11'),
        );

        $this->assertSame([], $sessions);
    }

    public function test_it_swaps_a_reversed_range(): void
    {
        $forward = $this->calendar->sessionsBetween(Carbon::parse('2026-09-06'), Carbon::parse('2026-09-10'));
        $reversed = $this->calendar->sessionsBetween(Carbon::parse('2026-09-10'), Carbon::parse('2026-09-06'));

        $this->assertSame($forward, $reversed);
    }

    public function test_session_count_between_matches_the_enumerated_list(): void
    {
        $count = $this->calendar->sessionCountBetween(
            Carbon::parse('2026-09-06'),
            Carbon::parse('2026-09-12'),
        );

        $this->assertSame(5, $count);
    }

    public function test_it_honours_a_custom_trading_week(): void
    {
        // A hypothetical Mon-Fri market.
        $calendar = new TradingSessionCalendar([1, 2, 3, 4, 5]);

        $sessions = $calendar->sessionsBetween(
            Carbon::parse('2026-09-06'), // Sunday
            Carbon::parse('2026-09-12'), // Saturday
        );

        $this->assertSame([
            '2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11',
        ], $sessions);
    }

    // ------------------------------------------------------------------
    // allDatesBetween() — every calendar day, no weekday filter at all.
    // This is what the backfill's calendar observation actually uses now;
    // sessionsBetween()/sessionCountBetween() above are kept only as a
    // legacy, non-authoritative hint (see the class docblock).
    // ------------------------------------------------------------------

    public function test_all_dates_between_includes_every_day_unfiltered(): void
    {
        // No weekday argument at all - the constructor default is [].
        $calendar = new TradingSessionCalendar;

        $dates = $calendar->allDatesBetween(
            Carbon::parse('2026-09-06'), // Sunday
            Carbon::parse('2026-09-12'), // Saturday
        );

        $this->assertSame([
            '2026-09-06', '2026-09-07', '2026-09-08',
            '2026-09-09', '2026-09-10', '2026-09-11', '2026-09-12',
        ], $dates);
    }

    public function test_all_dates_between_includes_both_endpoints_for_a_single_day(): void
    {
        $calendar = new TradingSessionCalendar;

        $dates = $calendar->allDatesBetween(Carbon::parse('2026-09-11'), Carbon::parse('2026-09-11'));

        $this->assertSame(['2026-09-11'], $dates);
    }

    public function test_all_dates_between_swaps_a_reversed_range(): void
    {
        $calendar = new TradingSessionCalendar;

        $forward = $calendar->allDatesBetween(Carbon::parse('2026-09-06'), Carbon::parse('2026-09-08'));
        $reversed = $calendar->allDatesBetween(Carbon::parse('2026-09-08'), Carbon::parse('2026-09-06'));

        $this->assertSame($forward, $reversed);
    }
}
