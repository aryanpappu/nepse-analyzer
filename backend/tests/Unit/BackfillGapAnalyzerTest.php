<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scraper\Backfill\BackfillGapAnalyzer;
use App\Services\Scraper\Backfill\MarketCalendarClassifier;
use PHPUnit\Framework\TestCase;

class BackfillGapAnalyzerTest extends TestCase
{
    private BackfillGapAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new BackfillGapAnalyzer;
    }

    // ------------------------------------------------------------------
    // findMissingSessions()
    // ------------------------------------------------------------------

    public function test_it_finds_confirmed_trading_days_missing_a_bar(): void
    {
        $confirmedTradingDays = ['2026-09-06', '2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10'];
        $existing = ['2026-09-06', '2026-09-08', '2026-09-10'];

        $gaps = $this->analyzer->findMissingSessions($confirmedTradingDays, $existing);

        $this->assertSame(['2026-09-07', '2026-09-09'], $gaps);
    }

    public function test_no_gaps_when_every_confirmed_trading_day_is_covered(): void
    {
        $confirmedTradingDays = ['2026-09-06', '2026-09-07'];

        $gaps = $this->analyzer->findMissingSessions($confirmedTradingDays, $confirmedTradingDays);

        $this->assertSame([], $gaps);
    }

    /**
     * A date that is merely "unknown" - never confirmed a trading day either
     * way - must never be asserted as a gap. Only dates the calendar has
     * actually confirmed trading on belong in $confirmedTradingDays in the
     * first place, so an unresolved date simply never appears here.
     */
    public function test_an_unresolved_date_is_never_treated_as_a_gap(): void
    {
        // 2026-09-07 is NOT in the confirmed set (still unknown), even though
        // this symbol has no bar for it either.
        $confirmedTradingDays = ['2026-09-06'];
        $existing = ['2026-09-06'];

        $gaps = $this->analyzer->findMissingSessions($confirmedTradingDays, $existing);

        $this->assertSame([], $gaps);
    }

    /**
     * The scenario the liquid-basket evidence exists for: the basket (a
     * subset of the whole market) has bars on a date, which is enough for
     * MarketCalendarClassifier to confirm it a trading day market-wide - but
     * THIS particular symbol still has no bar for it. That is a real,
     * symbol-specific gap, not a holiday, and findMissingSessions must
     * surface it.
     */
    public function test_a_date_the_basket_traded_on_is_a_real_gap_for_a_symbol_that_did_not(): void
    {
        $classifier = new MarketCalendarClassifier;
        $date = '2026-09-07';

        // The basket had bars this day (hasObservedBar=true), so the market
        // calendar confirms it a trading day regardless of what any single
        // other symbol did.
        $verdict = $classifier->classify(hasObservedBar: true, wasCheckedForTrading: true);
        $this->assertSame('trading_day', $verdict['classification']);

        $confirmedTradingDays = [$date];
        $thisSymbolsBars = []; // this symbol has no bar that day.

        $gaps = $this->analyzer->findMissingSessions($confirmedTradingDays, $thisSymbolsBars);

        $this->assertSame([$date], $gaps);
    }

    // ------------------------------------------------------------------
    // wellCoveredSpan()
    // ------------------------------------------------------------------

    public function test_well_covered_span_returns_null_when_nothing_qualifies(): void
    {
        $coverage = ['2026-09-06' => 1, '2026-09-07' => 0];

        $span = $this->analyzer->wellCoveredSpan($coverage, typicalBreadth: 5, wellCoveredFraction: 0.8);

        $this->assertNull($span);
    }

    public function test_well_covered_span_returns_null_for_a_non_positive_population(): void
    {
        $coverage = ['2026-09-06' => 5];

        $this->assertNull($this->analyzer->wellCoveredSpan($coverage, typicalBreadth: 0, wellCoveredFraction: 0.8));
        $this->assertNull($this->analyzer->wellCoveredSpan($coverage, typicalBreadth: -5, wellCoveredFraction: 0.8));
    }

    public function test_well_covered_span_is_the_min_and_max_of_qualifying_dates(): void
    {
        // Basket of 5, 80% fraction => needs >= 4 covered.
        $coverage = [
            '2026-08-01' => 5, // qualifies
            '2026-08-02' => 0, // a holiday embedded inside the span - does
            //                    not itself qualify, but does not need to.
            '2026-08-03' => 4, // qualifies
            '2026-07-15' => 1, // does not qualify - too sparse
        ];

        $span = $this->analyzer->wellCoveredSpan($coverage, typicalBreadth: 5, wellCoveredFraction: 0.8);

        $this->assertSame(['2026-08-01', '2026-08-03'], $span);
    }

    public function test_well_covered_span_extends_backwards_as_deeper_dates_qualify(): void
    {
        $baseline = ['2026-08-01' => 5, '2026-08-03' => 4];
        $firstSpan = $this->analyzer->wellCoveredSpan($baseline, typicalBreadth: 5, wellCoveredFraction: 0.8);
        $this->assertSame(['2026-08-01', '2026-08-03'], $firstSpan);

        // A later run has backfilled the basket further back, so an older
        // date now also has dense coverage.
        $deepened = $baseline + ['2025-01-05' => 5];
        $secondSpan = $this->analyzer->wellCoveredSpan($deepened, typicalBreadth: 5, wellCoveredFraction: 0.8);

        $this->assertSame(['2025-01-05', '2026-08-03'], $secondSpan);
    }

    // ------------------------------------------------------------------
    // withinSpan()
    // ------------------------------------------------------------------

    public function test_within_span_is_false_with_no_span_at_all(): void
    {
        $this->assertFalse($this->analyzer->withinSpan('2026-08-02', null));
    }

    public function test_within_span_accepts_dates_between_the_bounds_inclusive(): void
    {
        $span = ['2026-08-01', '2026-08-03'];

        $this->assertTrue($this->analyzer->withinSpan('2026-08-01', $span));
        $this->assertTrue($this->analyzer->withinSpan('2026-08-02', $span));
        $this->assertTrue($this->analyzer->withinSpan('2026-08-03', $span));
    }

    public function test_within_span_rejects_dates_outside_the_bounds(): void
    {
        $span = ['2026-08-01', '2026-08-03'];

        $this->assertFalse($this->analyzer->withinSpan('2026-07-31', $span));
        $this->assertFalse($this->analyzer->withinSpan('2026-08-04', $span));
    }
}
