<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PortfolioTransaction;
use App\Models\Stock;
use App\Services\Portfolio\CapitalGainsTaxAssessment;
use App\Services\Portfolio\CapitalGainsTaxCalculator;
use App\Services\Portfolio\CapitalGainsTaxRates;
use App\Services\Portfolio\PerformanceService;
use App\Services\Portfolio\PortfolioValuationService;
use App\Services\Portfolio\TransactionCostCalculator;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The join between the two subsystems: the FIFO round trips
 * `PerformanceService` already reconstructs, rated by the tax calculator.
 *
 * This is the part that would be wrong if the tax estimator had re-derived its
 * own lot matching. Which acquisition a disposal is paired to decides the band,
 * and FIFO is the only reading that says how long the money was really at work
 * — an average-cost date would put a two-year-old position in the short-term
 * band the moment a fresh lot was added beside it.
 *
 * No database: `closedTrades()` takes a collection of transactions precisely so
 * the ledger can be a literal here. The app is booted only because Eloquent's
 * casts need a connection resolver to exist; no query runs.
 */
final class CapitalGainsFifoTest extends TestCase
{
    private function performance(): PerformanceService
    {
        return new PerformanceService(new PortfolioValuationService);
    }

    private function transaction(
        string $symbol,
        string $type,
        float $quantity,
        float $price,
        string $date,
        float $fees = 0.0,
    ): PortfolioTransaction {
        $transaction = new PortfolioTransaction([
            'type' => $type,
            'quantity' => $quantity,
            'price' => $price,
            'commission' => $fees,
            'other_fees' => 0,
            'transacted_on' => $date,
        ]);

        $transaction->stock_id = crc32($symbol);
        $transaction->setRelation('stock', new Stock(['symbol' => $symbol]));

        return $transaction;
    }

    /**
     * @param  list<PortfolioTransaction>  $transactions
     * @return list<CapitalGainsTaxAssessment>
     */
    private function assess(array $transactions): array
    {
        $trades = $this->performance()->closedTrades(collect($transactions));

        return (new CapitalGainsTaxCalculator)->assessAll(
            $trades,
            CapitalGainsTaxRates::builtInTable()[CapitalGainsTaxRates::INVESTOR_INDIVIDUAL],
        );
    }

    // ------------------------------------------------------------------
    // FIFO decides the band
    // ------------------------------------------------------------------

    #[Test]
    public function the_oldest_lot_is_sold_first_and_earns_the_long_term_rate(): void
    {
        // Two lots, one old and one fresh, then a sale of exactly one lot's
        // worth. FIFO pairs it to the 2025 purchase — 365 days — so the long
        // term rate applies even though a newer lot exists.
        $assessments = $this->assess([
            $this->transaction('NTC', 'buy', 100, 800, '2025-06-01'),
            $this->transaction('NTC', 'buy', 100, 900, '2026-05-02'),
            $this->transaction('NTC', 'sell', 100, 1000, '2026-06-01'),
        ]);

        $this->assertCount(1, $assessments);
        $assessment = $assessments[0];

        $this->assertSame('2025-06-01', $assessment->openedOn);
        $this->assertSame(365, $assessment->daysHeld);
        $this->assertSame(CapitalGainsTaxRates::BAND_LONG_TERM, $assessment->rateBand);
        $this->assertSame(5.0, $assessment->ratePercent);

        // Cost basis is the weighted average of both lots (850), not the FIFO
        // lot's own price — that is deliberate, so the tax figure reconciles
        // with the realised gain the rest of the app reports.
        $this->assertSame(85_000.0, $assessment->costBasis);
        $this->assertSame(100_000.0, $assessment->proceeds);
        $this->assertSame(15_000.0, $assessment->grossGain);
        $this->assertSame(750.0, $assessment->estimatedTax);
        $this->assertSame(14_250.0, $assessment->netGain);
    }

    #[Test]
    public function once_the_old_lot_is_gone_the_fresh_one_is_short_term(): void
    {
        $assessments = $this->assess([
            $this->transaction('NTC', 'buy', 100, 800, '2025-06-01'),
            $this->transaction('NTC', 'buy', 100, 900, '2026-05-02'),
            $this->transaction('NTC', 'sell', 100, 1000, '2026-06-01'),
            $this->transaction('NTC', 'sell', 100, 1000, '2026-06-02'),
        ]);

        $this->assertCount(2, $assessments);

        // First disposal took the 2025 lot.
        $this->assertSame(CapitalGainsTaxRates::BAND_LONG_TERM, $assessments[0]->rateBand);

        // The second can only be the 2026 lot: 31 days, short term.
        $this->assertSame('2026-05-02', $assessments[1]->openedOn);
        $this->assertSame(31, $assessments[1]->daysHeld);
        $this->assertSame(CapitalGainsTaxRates::BAND_SHORT_TERM, $assessments[1]->rateBand);
        $this->assertSame(7.5, $assessments[1]->ratePercent);
    }

    #[Test]
    public function bonus_units_inherit_the_position_age_rather_than_the_award_date(): void
    {
        // A bonus award does not restart the clock. Treating it as a fresh
        // purchase would move a long-held position into the 7.5% band for
        // having been rewarded.
        $assessments = $this->assess([
            $this->transaction('NABIL', 'buy', 100, 500, '2025-01-10'),
            $this->transaction('NABIL', 'bonus', 20, 0, '2026-04-01'),
            $this->transaction('NABIL', 'sell', 120, 600, '2026-06-01'),
        ]);

        $this->assertCount(1, $assessments);
        $this->assertSame('2025-01-10', $assessments[0]->openedOn);
        $this->assertSame(507, $assessments[0]->daysHeld);
        $this->assertSame(CapitalGainsTaxRates::BAND_LONG_TERM, $assessments[0]->rateBand);
    }

    #[Test]
    public function a_partly_matched_sale_takes_the_weighted_acquisition_date(): void
    {
        // 200 sold against a 365-day lot and a 30-day lot, so the weighted date
        // lands between them and the disposal is short term. Half of it having
        // been held over a year does not make the whole of it long term.
        $assessments = $this->assess([
            $this->transaction('SCB', 'buy', 100, 500, '2025-06-01'),
            $this->transaction('SCB', 'buy', 100, 500, '2026-05-02'),
            $this->transaction('SCB', 'sell', 200, 600, '2026-06-01'),
        ]);

        $this->assertCount(1, $assessments);
        // 365 and 30 days weight to a midpoint of 197.5, floored to 197. The
        // half day is why this is not 198; the band is short term on either
        // reading, and the band is what sets the rate.
        $this->assertSame(197, $assessments[0]->daysHeld);
        $this->assertSame(CapitalGainsTaxRates::BAND_SHORT_TERM, $assessments[0]->rateBand);
    }

    #[Test]
    public function a_replayed_ledger_always_knows_its_acquisition_date(): void
    {
        $trades = $this->performance()->closedTrades(collect([
            $this->transaction('NTC', 'buy', 100, 800, '2026-01-10'),
            $this->transaction('NTC', 'sell', 100, 900, '2026-03-10'),
        ]));

        $this->assertCount(1, $trades);
        $this->assertTrue($trades[0]->acquisitionDateKnown);
        $this->assertSame(59, $trades[0]->holdingPeriodDays());
    }

    #[Test]
    public function a_sell_with_no_recorded_purchase_produces_no_disposal_at_all(): void
    {
        // Worth pinning down: an unfunded sell is skipped rather than booked as
        // a zero-cost windfall. So the ledger cannot manufacture a taxable gain
        // out of a missing purchase — it under-reports instead, which is why
        // the tax report states its coverage.
        $assessments = $this->assess([
            $this->transaction('HIDCL', 'sell', 100, 600, '2026-06-01'),
        ]);

        $this->assertSame([], $assessments);
    }

    // ------------------------------------------------------------------
    // Real costs against the tax figure
    // ------------------------------------------------------------------

    #[Test]
    public function computed_costs_flip_a_marginal_round_trip_into_an_untaxed_loss(): void
    {
        $costs = new TransactionCostCalculator;
        $quantity = 100.0;
        $buyPrice = 500.0;
        $sellPrice = 502.0;

        $trip = $costs->roundTrip($quantity, $buyPrice, $sellPrice, Carbon::parse('2026-01-05'));

        // Feed the computed figures in as the trade's fees — which is how a
        // paper fill or a backtest would book them, and the reason the cost
        // calculator is a prerequisite for either.
        $assessments = $this->assess([
            $this->transaction('NTC', 'buy', $quantity, $buyPrice, '2026-01-05', $trip['buy']->total),
            $this->transaction('NTC', 'sell', $quantity, $sellPrice, '2026-02-05', $trip['sell']->total),
        ]);

        $this->assertCount(1, $assessments);
        $assessment = $assessments[0];

        // Gross of costs the trade made NPR 200. Net of them it lost money, so
        // there is nothing to tax — a real zero, with the rate still reported
        // because the holding period is known.
        $this->assertLessThan(0.0, $assessment->grossGain);
        $this->assertSame(0.0, $assessment->estimatedTax);
        $this->assertSame(7.5, $assessment->ratePercent);
        $this->assertSame(31, $assessment->daysHeld);

        $aggregate = (new CapitalGainsTaxCalculator)->aggregate($assessments);

        // Taxable gain is zero and the loss is reported separately, so the two
        // are never confused for one another.
        $this->assertSame(0.0, $aggregate['taxable_gain']);
        $this->assertSame($assessment->grossGain, $aggregate['realised_loss']);
        $this->assertSame(0.0, $aggregate['estimated_tax']);
        $this->assertNull($aggregate['effective_tax_rate_percent']);
        $this->assertTrue($aggregate['coverage_complete']);
    }
}
