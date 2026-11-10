<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\MarketCalendarDay;
use App\Services\Scraper\Backfill\MarketCalendarClassifier;
use PHPUnit\Framework\TestCase;

class MarketCalendarClassifierTest extends TestCase
{
    private MarketCalendarClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new MarketCalendarClassifier;
    }

    public function test_an_observed_bar_is_a_trading_day_full_stop(): void
    {
        $verdict = $this->classifier->classify(hasObservedBar: true, wasCheckedForTrading: false);

        $this->assertSame(MarketCalendarDay::TRADING_DAY, $verdict['classification']);
        $this->assertSame(MarketCalendarDay::CLASSIFIED_BY_OBSERVED, $verdict['classified_by']);
    }

    /**
     * The old flat `nepse.trading_days` constant excluded Friday entirely.
     * The classifier takes no weekday as input at all, so a bar on what used
     * to be an excluded day is still, correctly, a trading day - proving the
     * "no weekday reasoning" property structurally rather than by accident.
     */
    public function test_a_friday_with_bars_is_a_trading_day_even_though_the_old_constant_excluded_it(): void
    {
        // 2026-09-11 is a Friday. The classifier never sees the date itself,
        // only what was observed - which is the point.
        $verdict = $this->classifier->classify(hasObservedBar: true, wasCheckedForTrading: false);

        $this->assertSame(MarketCalendarDay::TRADING_DAY, $verdict['classification']);
    }

    public function test_a_checked_date_with_nothing_observed_is_a_holiday(): void
    {
        // e.g. a Sunday after NEPSE moved to a Monday-Friday week: no bars,
        // but the liquid basket's fetch coverage genuinely reached back
        // across it, so the silence means something.
        $verdict = $this->classifier->classify(hasObservedBar: false, wasCheckedForTrading: true);

        $this->assertSame(MarketCalendarDay::HOLIDAY, $verdict['classification']);
        $this->assertSame(MarketCalendarDay::CLASSIFIED_BY_OBSERVED, $verdict['classified_by']);
    }

    /**
     * A holiday confirmed this way is not a permanent gap: it is simply
     * excluded from a symbol's confirmed trading days (see
     * BackfillGapAnalyzer::findMissingSessions()), so it stops being chased,
     * without ever having been treated as "unfetchable" or blocking further
     * backfill of the surrounding history.
     */
    public function test_a_holiday_is_classified_not_left_pending_forever(): void
    {
        $verdict = $this->classifier->classify(hasObservedBar: false, wasCheckedForTrading: true);

        $this->assertNotSame(MarketCalendarDay::UNKNOWN, $verdict['classification']);
    }

    public function test_an_unattempted_date_is_never_classified(): void
    {
        $verdict = $this->classifier->classify(hasObservedBar: false, wasCheckedForTrading: false);

        $this->assertSame(MarketCalendarDay::UNKNOWN, $verdict['classification']);
        $this->assertNull($verdict['classified_by']);
    }

    /**
     * A failed attempt (a source error, a network failure) must never look
     * like evidence of a holiday. The impure caller is responsible for only
     * setting wasCheckedForTrading=true off a *successful* run
     * (App\Models\ScrapeRun status success/partial) or genuine basket
     * coverage - here we just confirm the pure decision: false stays false,
     * which resolves to unknown, never holiday.
     */
    public function test_a_failed_attempt_is_not_evidence_of_a_holiday(): void
    {
        $verdict = $this->classifier->classify(hasObservedBar: false, wasCheckedForTrading: false);

        $this->assertNotSame(MarketCalendarDay::HOLIDAY, $verdict['classification']);
        $this->assertSame(MarketCalendarDay::UNKNOWN, $verdict['classification']);
    }

    public function test_a_manual_override_beats_an_inferred_holiday(): void
    {
        // Automatic evidence says holiday, but a human knows better.
        $verdict = $this->classifier->classify(
            hasObservedBar: false,
            wasCheckedForTrading: true,
            manualClassification: MarketCalendarDay::TRADING_DAY,
        );

        $this->assertSame(MarketCalendarDay::TRADING_DAY, $verdict['classification']);
        $this->assertSame(MarketCalendarDay::CLASSIFIED_BY_MANUAL, $verdict['classified_by']);
    }

    public function test_a_manual_override_beats_an_observed_trading_day(): void
    {
        // Automatic evidence says trading day (a bar exists - e.g. a data
        // error), but a human corrects it to a holiday.
        $verdict = $this->classifier->classify(
            hasObservedBar: true,
            wasCheckedForTrading: false,
            manualClassification: MarketCalendarDay::HOLIDAY,
        );

        $this->assertSame(MarketCalendarDay::HOLIDAY, $verdict['classification']);
        $this->assertSame(MarketCalendarDay::CLASSIFIED_BY_MANUAL, $verdict['classified_by']);
    }

    public function test_a_manual_override_wins_even_on_an_unattempted_date(): void
    {
        // A human can pre-emptively assert a known festival closure before
        // any evidence has accumulated either way.
        $verdict = $this->classifier->classify(
            hasObservedBar: false,
            wasCheckedForTrading: false,
            manualClassification: MarketCalendarDay::HOLIDAY,
        );

        $this->assertSame(MarketCalendarDay::HOLIDAY, $verdict['classification']);
        $this->assertSame(MarketCalendarDay::CLASSIFIED_BY_MANUAL, $verdict['classified_by']);
    }
}
