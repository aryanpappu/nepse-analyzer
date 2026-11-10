<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Portfolio\HoldingMark;
use App\Services\Portfolio\MarkSourceReconciler;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The precedence rule that stops the two mark sources disagreeing.
 *
 * Pure and DB-free: the reconciler takes candidate prices and their dates as
 * plain values, so the rule can be pinned down without a portfolio, a stock,
 * or a price table — which is also what lets `PortfolioTimeSeries` and
 * `PortfolioValuationService` share one decision instead of each reading its
 * own tables and reaching its own conclusion.
 *
 * NICADF is the case that prompted this: `stocks.last_price` 10.05 dated
 * 2026-08-05 by a MeroShare import, zero rows in `stock_prices` and zero in
 * `fund_navs`.
 */
final class MarkSourceReconcilerTest extends TestCase
{
    private const NICADF = 349;

    private function reconciler(): MarkSourceReconciler
    {
        return new MarkSourceReconciler;
    }

    // ------------------------------------------------------------------
    // Precedence
    // ------------------------------------------------------------------

    #[Test]
    public function a_series_close_wins_over_the_spot_column(): void
    {
        $mark = $this->reconciler()->reconcile(
            stockId: 1,
            asAt: Carbon::parse('2026-09-09'),
            seriesPrice: 505.0,
            seriesDate: Carbon::parse('2026-09-08'),
            seriesSource: HoldingMark::SOURCE_CLOSE,
            spotPrice: 511.0,
            spotDate: Carbon::parse('2026-09-09'),
        );

        // The series is dated and reproducible, so it marks — even though the
        // spot column is a day fresher.
        $this->assertSame(505.0, $mark->price);
        $this->assertSame(HoldingMark::SOURCE_CLOSE, $mark->source);
        $this->assertTrue($mark->seriesBacked);
        $this->assertSame('2026-09-08', $mark->asOf?->toDateString());
        $this->assertSame(1, $mark->staleDays);
    }

    #[Test]
    public function a_fund_with_no_quote_is_marked_from_its_nav(): void
    {
        $mark = $this->reconciler()->reconcile(
            stockId: 2,
            asAt: Carbon::parse('2026-09-09'),
            seriesPrice: 11.42,
            seriesDate: Carbon::parse('2026-08-31'),
            seriesSource: HoldingMark::SOURCE_NAV,
            spotPrice: null,
            spotDate: null,
        );

        $this->assertSame(11.42, $mark->price);
        $this->assertSame(HoldingMark::SOURCE_NAV, $mark->source);
        $this->assertTrue($mark->seriesBacked);
    }

    // ------------------------------------------------------------------
    // The NICADF case
    // ------------------------------------------------------------------

    #[Test]
    public function a_dated_spot_price_marks_dates_on_or_after_its_own_date(): void
    {
        $mark = $this->reconciler()->reconcile(
            stockId: self::NICADF,
            asAt: Carbon::parse('2026-09-09'),
            seriesPrice: null,
            seriesDate: null,
            seriesSource: null,
            spotPrice: 10.05,
            spotDate: Carbon::parse('2026-08-05'),
        );

        // Priced — so the valuation service and the time series now agree that
        // this position counts, instead of one dropping it silently.
        $this->assertTrue($mark->isPriced());
        $this->assertSame(10.05, $mark->price);
        $this->assertSame(HoldingMark::SOURCE_SPOT, $mark->source);
        $this->assertSame('2026-08-05', $mark->asOf?->toDateString());
        $this->assertSame(35, $mark->staleDays);

        // But flagged: nothing reproducible stands behind it, so any value it
        // contributes to is provisional.
        $this->assertFalse($mark->seriesBacked);
        $this->assertTrue($mark->isStale);
        $this->assertStringContainsString('provisional', $mark->reason);
        $this->assertSame(10_050.0, $mark->valueOf(1_000.0));
    }

    #[Test]
    public function a_spot_price_cannot_be_back_dated_to_an_earlier_boundary(): void
    {
        // The fiscal-year boundary before the import happened. An undated-in-
        // the-series price cannot mark a historical date, which is why "make
        // the time series read last_price" would have been the wrong fix.
        $mark = $this->reconciler()->reconcile(
            stockId: self::NICADF,
            asAt: Carbon::parse('2025-07-16'),
            seriesPrice: null,
            seriesDate: null,
            seriesSource: null,
            spotPrice: 10.05,
            spotDate: Carbon::parse('2026-08-05'),
        );

        $this->assertFalse($mark->isPriced());
        $this->assertNull($mark->price);
        $this->assertSame(HoldingMark::SOURCE_NONE, $mark->source);
        $this->assertNull($mark->valueOf(1_000.0));
        $this->assertStringContainsString('cannot be back-dated', $mark->reason);
    }

    #[Test]
    public function an_undated_spot_price_is_refused_outright(): void
    {
        $mark = $this->reconciler()->reconcile(
            stockId: 3,
            asAt: Carbon::parse('2026-09-09'),
            seriesPrice: null,
            seriesDate: null,
            seriesSource: null,
            spotPrice: 250.0,
            spotDate: null,
        );

        // A price with no date cannot mark any date, today included.
        $this->assertFalse($mark->isPriced());
        $this->assertSame(HoldingMark::SOURCE_NONE, $mark->source);
        $this->assertStringContainsString('last_traded_on', $mark->reason);
    }

    // ------------------------------------------------------------------
    // Unpriced is named, never valued at cost
    // ------------------------------------------------------------------

    #[Test]
    public function a_holding_with_no_price_is_excluded_and_the_reason_names_it(): void
    {
        $mark = $this->reconciler()->reconcile(
            stockId: 4,
            asAt: Carbon::parse('2026-09-09'),
            seriesPrice: null,
            seriesDate: null,
            seriesSource: null,
            spotPrice: null,
            spotDate: null,
        );

        // Null, never 0.0, and never the cost basis — treating cost as value
        // would report a 0% gain as though it had been measured.
        $this->assertNull($mark->price);
        $this->assertNull($mark->valueOf(500.0));
        $this->assertNull($mark->staleDays);
        $this->assertSame(HoldingMark::SOURCE_NONE, $mark->source);
        $this->assertFalse($mark->seriesBacked);
        $this->assertStringContainsString('rather than counted at cost', $mark->reason);
        $this->assertStringContainsString('2026-09-09', $mark->reason);
    }

    #[Test]
    public function a_zero_or_negative_price_is_not_a_price(): void
    {
        $mark = $this->reconciler()->reconcile(
            stockId: 5,
            asAt: Carbon::parse('2026-09-09'),
            seriesPrice: 0.0,
            seriesDate: Carbon::parse('2026-09-08'),
            seriesSource: HoldingMark::SOURCE_CLOSE,
            spotPrice: 0.0,
            spotDate: Carbon::parse('2026-09-08'),
        );

        $this->assertFalse($mark->isPriced());
        $this->assertSame(HoldingMark::SOURCE_NONE, $mark->source);
    }

    // ------------------------------------------------------------------
    // Staleness reports, it never suppresses
    // ------------------------------------------------------------------

    #[Test]
    public function staleness_is_reported_and_never_discards_the_only_mark(): void
    {
        $mark = $this->reconciler()->reconcile(
            stockId: self::NICADF,
            asAt: Carbon::parse('2027-09-09'),
            seriesPrice: null,
            seriesDate: null,
            seriesSource: null,
            spotPrice: 10.05,
            spotDate: Carbon::parse('2026-08-05'),
            staleAfterDays: 30,
        );

        // Well over a year old and still priced. Dropping it would put the
        // position straight back into the excluded list, which is the outcome
        // this exists to avoid.
        $this->assertTrue($mark->isStale);
        $this->assertTrue($mark->isPriced());
        $this->assertSame(400, $mark->staleDays);
    }

    #[Test]
    public function the_staleness_threshold_is_a_parameter(): void
    {
        $reconciler = $this->reconciler();

        $tight = $reconciler->reconcile(
            stockId: 6,
            asAt: Carbon::parse('2026-09-09'),
            seriesPrice: 500.0,
            seriesDate: Carbon::parse('2026-09-04'),
            seriesSource: HoldingMark::SOURCE_CLOSE,
            spotPrice: null,
            spotDate: null,
            staleAfterDays: 3,
        );
        $loose = $reconciler->reconcile(
            stockId: 6,
            asAt: Carbon::parse('2026-09-09'),
            seriesPrice: 500.0,
            seriesDate: Carbon::parse('2026-09-04'),
            seriesSource: HoldingMark::SOURCE_CLOSE,
            spotPrice: null,
            spotDate: null,
            staleAfterDays: 30,
        );

        $this->assertTrue($tight->isStale);
        $this->assertFalse($loose->isStale);
        $this->assertSame($tight->price, $loose->price);
    }

    // ------------------------------------------------------------------
    // Divergence between the two sources
    // ------------------------------------------------------------------

    #[Test]
    public function it_reports_a_disagreement_between_the_two_sources(): void
    {
        $divergence = $this->reconciler()->divergence(seriesPrice: 500.0, spotPrice: 511.0);

        $this->assertTrue($divergence['diverges']);
        $this->assertSame(11.0, $divergence['difference']);
        $this->assertSame(2.2, $divergence['difference_percent']);
    }

    #[Test]
    public function agreement_and_a_missing_source_both_report_no_divergence(): void
    {
        $reconciler = $this->reconciler();

        $agrees = $reconciler->divergence(500.0, 500.0);
        $this->assertFalse($agrees['diverges']);
        $this->assertSame(0.0, $agrees['difference']);

        // Only one source present is not a disagreement, and the difference is
        // unknown rather than zero.
        $oneSided = $reconciler->divergence(null, 500.0);
        $this->assertFalse($oneSided['diverges']);
        $this->assertNull($oneSided['difference']);
        $this->assertNull($oneSided['difference_percent']);
    }
}
