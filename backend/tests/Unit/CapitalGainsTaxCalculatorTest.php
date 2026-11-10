<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Portfolio\CapitalGainsTaxAssessment;
use App\Services\Portfolio\CapitalGainsTaxCalculator;
use App\Services\Portfolio\CapitalGainsTaxRates;
use App\Services\Portfolio\ClosedTrade;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The holding-period rule and the aggregate, over hand-built round trips.
 *
 * No database. `ClosedTrade` is already a plain value object, so the tax
 * calculator can be driven with literals and every expected rupee figure
 * checked by hand — the same arrangement `InterestAccrualCalculatorTest` uses.
 *
 * The 365-day boundary is tested from both sides deliberately. It is the one
 * line in this subsystem where being one day out changes the rate by a third.
 * A disposal immediately before the boundary must use the short-term rate.
 */
final class CapitalGainsTaxCalculatorTest extends TestCase
{
    private function calculator(): CapitalGainsTaxCalculator
    {
        return new CapitalGainsTaxCalculator;
    }

    private function individual(): CapitalGainsTaxRates
    {
        return CapitalGainsTaxRates::builtInTable()[CapitalGainsTaxRates::INVESTOR_INDIVIDUAL];
    }

    private function institution(): CapitalGainsTaxRates
    {
        return CapitalGainsTaxRates::builtInTable()[CapitalGainsTaxRates::INVESTOR_INSTITUTION];
    }

    /**
     * A round trip held for exactly $days, earning exactly $gain.
     */
    private function trade(
        int $days,
        float $gain,
        string $symbol = 'NTC',
        string $closedOn = '2026-06-01',
        bool $acquisitionDateKnown = true,
    ): ClosedTrade {
        $closed = Carbon::parse($closedOn)->startOfDay();
        $costBasis = 100_000.0;

        return new ClosedTrade(
            symbol: $symbol,
            openedOn: $closed->copy()->subDays($days),
            closedOn: $closed,
            quantity: 100.0,
            costBasis: $costBasis,
            proceeds: $costBasis + $gain,
            fees: 0.0,
            gain: $gain,
            acquisitionDateKnown: $acquisitionDateKnown,
        );
    }

    // ------------------------------------------------------------------
    // The 365-day boundary
    // ------------------------------------------------------------------

    #[Test]
    public function at_364_days_the_short_term_rate_applies(): void
    {
        $assessment = $this->calculator()->assess($this->trade(364, 10_000.0), $this->individual());

        $this->assertSame(364, $assessment->daysHeld);
        $this->assertSame(CapitalGainsTaxRates::BAND_SHORT_TERM, $assessment->rateBand);
        $this->assertSame(7.5, $assessment->ratePercent);
        $this->assertSame(750.0, $assessment->estimatedTax);
        $this->assertSame(9_250.0, $assessment->netGain);
        $this->assertStringContainsString('364 days', $assessment->rateReason);
        $this->assertStringContainsString('under the 365-day threshold', $assessment->rateReason);
    }

    #[Test]
    public function at_365_days_the_long_term_rate_applies(): void
    {
        $assessment = $this->calculator()->assess($this->trade(365, 10_000.0), $this->individual());

        $this->assertSame(365, $assessment->daysHeld);
        $this->assertSame(CapitalGainsTaxRates::BAND_LONG_TERM, $assessment->rateBand);
        $this->assertSame(5.0, $assessment->ratePercent);
        $this->assertSame(500.0, $assessment->estimatedTax);
        $this->assertSame(9_500.0, $assessment->netGain);
        $this->assertStringContainsString('at or above the 365-day threshold', $assessment->rateReason);
    }

    #[Test]
    public function one_day_across_the_boundary_is_worth_a_third_of_the_tax(): void
    {
        $calculator = $this->calculator();
        $rates = $this->individual();

        $short = $calculator->assess($this->trade(364, 100_000.0), $rates);
        $long = $calculator->assess($this->trade(365, 100_000.0), $rates);

        $this->assertSame(7_500.0, $short->estimatedTax);
        $this->assertSame(5_000.0, $long->estimatedTax);
        $this->assertSame(2_500.0, round((float) $short->estimatedTax - (float) $long->estimatedTax, 2));
    }

    #[Test]
    public function the_threshold_is_a_parameter_not_a_constant(): void
    {
        // 365 days is current law, not arithmetic. A configured threshold has
        // to actually move the boundary.
        $rates = CapitalGainsTaxRates::fromArray(CapitalGainsTaxRates::INVESTOR_INDIVIDUAL, [
            'long_term_threshold_days' => 730,
            'short_term_rate_percent' => 7.5,
            'long_term_rate_percent' => 5.0,
        ]);

        $assessment = $this->calculator()->assess($this->trade(400, 10_000.0), $rates);

        $this->assertSame(CapitalGainsTaxRates::BAND_SHORT_TERM, $assessment->rateBand);
        $this->assertSame(7.5, $assessment->ratePercent);
        $this->assertStringContainsString('730-day threshold', $assessment->rateReason);
    }

    // ------------------------------------------------------------------
    // Investor class
    // ------------------------------------------------------------------

    #[Test]
    public function an_institution_pays_a_flat_rate_and_the_reason_does_not_claim_otherwise(): void
    {
        $calculator = $this->calculator();
        $rates = $this->institution();

        $short = $calculator->assess($this->trade(30, 10_000.0), $rates);
        $long = $calculator->assess($this->trade(1_000, 10_000.0), $rates);

        $this->assertFalse($rates->hasHoldingPeriodSplit());
        $this->assertSame(CapitalGainsTaxRates::BAND_FLAT, $short->rateBand);
        $this->assertSame(CapitalGainsTaxRates::BAND_FLAT, $long->rateBand);
        $this->assertSame(1_000.0, $short->estimatedTax);
        $this->assertSame(1_000.0, $long->estimatedTax);
        $this->assertStringContainsString('regardless of holding period', $long->rateReason);
    }

    // ------------------------------------------------------------------
    // Losses, and the disposal that cannot be rated
    // ------------------------------------------------------------------

    #[Test]
    public function a_loss_is_taxed_at_zero_which_is_a_real_answer(): void
    {
        $assessment = $this->calculator()->assess($this->trade(100, -5_000.0), $this->individual());

        $this->assertSame(-5_000.0, $assessment->grossGain);
        // Zero, not null: tax is charged on a gain and there is no gain. The
        // rate is still reported, because it is known.
        $this->assertSame(0.0, $assessment->estimatedTax);
        $this->assertSame(-5_000.0, $assessment->netGain);
        $this->assertSame(7.5, $assessment->ratePercent);
        $this->assertTrue($assessment->isAssessable);
        $this->assertFalse($assessment->isGain());
    }

    #[Test]
    public function a_disposal_with_no_acquisition_date_is_excluded_and_named(): void
    {
        $trade = $this->trade(0, 20_000.0, symbol: 'SCB', acquisitionDateKnown: false);
        $assessment = $this->calculator()->assess($trade, $this->individual());

        // The FIFO replay falls back to the sell date, which reads as zero days
        // held. Rating that at the short-term rate would be a guess presented
        // as a figure.
        $this->assertNull($assessment->daysHeld);
        $this->assertNull($assessment->openedOn);
        $this->assertNull($assessment->rateBand);
        $this->assertNull($assessment->ratePercent);
        $this->assertNull($assessment->estimatedTax);
        $this->assertNull($assessment->netGain);
        $this->assertFalse($assessment->isAssessable);
        $this->assertSame(
            CapitalGainsTaxAssessment::EXCLUDED_NO_ACQUISITION_DATE,
            $assessment->exclusionReason,
        );
        $this->assertStringContainsString('holding period', $assessment->rateReason);

        // And the aggregate names it rather than only counting it.
        $aggregate = $this->calculator()->aggregate([$assessment]);

        $this->assertSame(1, $aggregate['disposals']);
        $this->assertSame(0, $aggregate['assessable_disposals']);
        $this->assertSame(1, $aggregate['excluded_disposals']);
        $this->assertSame(['SCB'], $aggregate['excluded_symbols']);
        $this->assertSame(20_000.0, $aggregate['excluded_gross_gain']);
        $this->assertSame(0.0, $aggregate['coverage_percent']);
        $this->assertFalse($aggregate['coverage_complete']);

        // Nothing assessable means every money figure is unknown, not zero.
        $this->assertNull($aggregate['estimated_tax']);
        $this->assertNull($aggregate['gross_gain']);
        $this->assertNull($aggregate['taxable_gain']);
        $this->assertNull($aggregate['net_gain']);
    }

    // ------------------------------------------------------------------
    // Aggregation
    // ------------------------------------------------------------------

    #[Test]
    public function it_aggregates_by_band_and_reports_its_coverage(): void
    {
        $assessments = $this->calculator()->assessAll([
            $this->trade(100, 40_000.0, symbol: 'NTC'),      // short term
            $this->trade(400, 60_000.0, symbol: 'SCB'),      // long term
            $this->trade(200, -10_000.0, symbol: 'NABIL'),   // short-term loss
        ], $this->individual());

        $aggregate = $this->calculator()->aggregate($assessments);

        $this->assertSame(3, $aggregate['disposals']);
        $this->assertSame(3, $aggregate['assessable_disposals']);
        $this->assertSame(100.0, $aggregate['coverage_percent']);
        $this->assertTrue($aggregate['coverage_complete']);
        $this->assertNull($aggregate['excluded_gross_gain']);

        // Winners only carry tax: 40,000 @ 7.5% = 3,000, 60,000 @ 5% = 3,000.
        $this->assertSame(100_000.0, $aggregate['taxable_gain']);
        $this->assertSame(6_000.0, $aggregate['estimated_tax']);

        // Gross gain is the same disposals net of the loss — the figure that
        // says what the year earned, as opposed to what tax is charged on.
        $this->assertSame(90_000.0, $aggregate['gross_gain']);
        $this->assertSame(-10_000.0, $aggregate['realised_loss']);
        $this->assertSame(84_000.0, $aggregate['net_gain']);

        // The loss does not reduce the tax, which is the point of reporting
        // both numbers.
        $this->assertSame(6.0, $aggregate['effective_tax_rate_percent']);
        $this->assertStringContainsString('withheld per disposal', $aggregate['loss_offset_note']);

        $bands = collect($aggregate['bands'])->keyBy('band');
        $this->assertSame(2, $bands[CapitalGainsTaxRates::BAND_SHORT_TERM]['disposals']);
        $this->assertSame(40_000.0, $bands[CapitalGainsTaxRates::BAND_SHORT_TERM]['taxable_gain']);
        $this->assertSame(3_000.0, $bands[CapitalGainsTaxRates::BAND_SHORT_TERM]['estimated_tax']);
        $this->assertSame(1, $bands[CapitalGainsTaxRates::BAND_LONG_TERM]['disposals']);
        $this->assertSame(3_000.0, $bands[CapitalGainsTaxRates::BAND_LONG_TERM]['estimated_tax']);
    }

    #[Test]
    public function partial_coverage_is_stated_as_a_floor_not_an_estimate(): void
    {
        $assessments = $this->calculator()->assessAll([
            $this->trade(100, 40_000.0, symbol: 'NTC'),
            $this->trade(0, 25_000.0, symbol: 'HIDCL', acquisitionDateKnown: false),
        ], $this->individual());

        $aggregate = $this->calculator()->aggregate($assessments);

        $this->assertSame(50.0, $aggregate['coverage_percent']);
        $this->assertFalse($aggregate['coverage_complete']);
        $this->assertSame(['HIDCL'], $aggregate['excluded_symbols']);
        $this->assertSame(25_000.0, $aggregate['excluded_gross_gain']);
        $this->assertSame(3_000.0, $aggregate['estimated_tax']);
        $this->assertStringContainsString('floor', $aggregate['coverage_note']);
        $this->assertStringContainsString('1 of 2 disposals', $aggregate['coverage_note']);
    }

    #[Test]
    public function no_disposals_is_not_a_nil_tax_bill(): void
    {
        $aggregate = $this->calculator()->aggregate([]);

        $this->assertSame(0, $aggregate['disposals']);
        $this->assertNull($aggregate['coverage_percent']);
        $this->assertNull($aggregate['estimated_tax']);
        $this->assertNull($aggregate['gross_gain']);
        $this->assertFalse($aggregate['coverage_complete']);
        $this->assertStringContainsString('nothing to assess', $aggregate['coverage_note']);
    }

    // ------------------------------------------------------------------
    // The user's own shape: young capital, mostly short-term
    // ------------------------------------------------------------------

    #[Test]
    public function young_capital_lands_mostly_in_the_short_term_band(): void
    {
        // Average age around 288 days, most of it inside the threshold.
        $assessments = $this->calculator()->assessAll([
            $this->trade(120, 15_000.0, symbol: 'NTC'),
            $this->trade(240, 25_000.0, symbol: 'SCB'),
            $this->trade(300, 30_000.0, symbol: 'NABIL'),
            $this->trade(420, 30_000.0, symbol: 'HIDCL'),
        ], $this->individual());

        $aggregate = $this->calculator()->aggregate($assessments);
        $bands = collect($aggregate['bands'])->keyBy('band');

        $this->assertSame(3, $bands[CapitalGainsTaxRates::BAND_SHORT_TERM]['disposals']);
        $this->assertSame(1, $bands[CapitalGainsTaxRates::BAND_LONG_TERM]['disposals']);

        // 70,000 @ 7.5% = 5,250 plus 30,000 @ 5% = 1,500.
        $this->assertSame(6_750.0, $aggregate['estimated_tax']);

        // Every disposal states its day count and which side of the boundary
        // it fell, which is the difference that matters on a young book.
        foreach ($assessments as $assessment) {
            $this->assertNotNull($assessment->daysHeld);
            $this->assertNotNull($assessment->rateBand);
            $this->assertStringContainsString('365-day threshold', $assessment->rateReason);
        }
    }
}
