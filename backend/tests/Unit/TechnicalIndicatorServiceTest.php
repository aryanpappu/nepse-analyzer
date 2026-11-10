<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Indicators\TechnicalIndicatorService;
use PHPUnit\Framework\TestCase;

class TechnicalIndicatorServiceTest extends TestCase
{
    private TechnicalIndicatorService $indicators;

    protected function setUp(): void
    {
        parent::setUp();
        $this->indicators = new TechnicalIndicatorService;
    }

    public function test_sma_is_aligned_to_the_input_and_left_padded_with_nulls(): void
    {
        $result = $this->indicators->sma([1, 2, 3, 4, 5], 3);

        // Alignment is the contract every caller relies on: closes[i] must line
        // up with sma[i] without any offset bookkeeping.
        $this->assertCount(5, $result);
        $this->assertNull($result[0]);
        $this->assertNull($result[1]);
        $this->assertSame(2.0, $result[2]);
        $this->assertSame(3.0, $result[3]);
        $this->assertSame(4.0, $result[4]);
    }

    public function test_sma_returns_all_nulls_when_history_is_shorter_than_the_period(): void
    {
        $this->assertSame([null, null], $this->indicators->sma([10, 20], 5));
    }

    public function test_ema_is_seeded_with_the_sma_of_the_first_period(): void
    {
        $result = $this->indicators->ema([1, 2, 3, 4, 5], 3);

        $this->assertNull($result[1]);
        // Seed = mean(1,2,3) = 2
        $this->assertSame(2.0, $result[2]);
        // Then 2 + (4-2) * 2/(3+1) = 3.0
        $this->assertSame(3.0, $result[3]);
    }

    /**
     * Wilder's RSI against the canonical worked example from his own book,
     * which is the reference every charting platform matches.
     */
    public function test_rsi_matches_wilders_reference_series(): void
    {
        $closes = [
            44.34, 44.09, 44.15, 43.61, 44.33, 44.83, 45.10, 45.42,
            45.84, 46.08, 45.89, 46.03, 45.61, 46.28, 46.28,
        ];

        $result = $this->indicators->rsi($closes, 14);

        $this->assertNull($result[13], 'RSI is undefined until period+1 closes exist');
        $this->assertNotNull($result[14]);
        // Published value for this series is ~70.46.
        $this->assertEqualsWithDelta(70.46, $result[14], 0.1);
    }

    public function test_rsi_is_100_when_every_change_is_a_gain(): void
    {
        $closes = range(1, 20);

        $result = $this->indicators->rsi($closes, 14);

        // A stock that only rose has no average loss; guarding the division is
        // what keeps this from being INF or a divide-by-zero.
        $this->assertSame(100.0, $result[14]);
    }

    public function test_rsi_is_neutral_when_the_price_never_moves(): void
    {
        $result = $this->indicators->rsi(array_fill(0, 20, 500.0), 14);

        $this->assertSame(50.0, $result[14]);
    }

    public function test_macd_histogram_is_the_difference_between_macd_and_signal(): void
    {
        $closes = [];
        for ($i = 0; $i < 60; $i++) {
            $closes[] = 100 + sin($i / 5) * 10;
        }

        $result = $this->indicators->macd($closes);

        $this->assertCount(60, $result['macd']);
        $this->assertCount(60, $result['signal']);
        $this->assertCount(60, $result['histogram']);

        // The signal line is an EMA of MACD, so it starts later than MACD does.
        $this->assertNotNull($result['macd'][30]);
        $this->assertNotNull($result['signal'][40]);

        foreach ($result['histogram'] as $index => $value) {
            if ($value === null) {
                continue;
            }

            $this->assertEqualsWithDelta(
                $result['macd'][$index] - $result['signal'][$index],
                $value,
                1e-9,
                "Histogram at {$index} must equal MACD minus signal",
            );
        }
    }

    public function test_bollinger_bands_bracket_the_moving_average(): void
    {
        $closes = [];
        for ($i = 0; $i < 40; $i++) {
            $closes[] = 100 + ($i % 5);
        }

        $result = $this->indicators->bollingerBands($closes, 20, 2.0);

        $this->assertNotNull($result['middle'][25]);
        $this->assertGreaterThan($result['middle'][25], $result['upper'][25]);
        $this->assertLessThan($result['middle'][25], $result['lower'][25]);
    }

    public function test_bollinger_bands_collapse_onto_the_average_with_no_volatility(): void
    {
        $result = $this->indicators->bollingerBands(array_fill(0, 25, 200.0), 20);

        $this->assertEqualsWithDelta(200.0, $result['upper'][24], 1e-9);
        $this->assertEqualsWithDelta(200.0, $result['lower'][24], 1e-9);
    }

    public function test_atr_degrades_gracefully_when_only_closes_are_available(): void
    {
        $closes = [10, 11, 12, 11, 13, 14, 13, 15, 16, 15, 17, 18, 17, 19, 20, 21];

        // Sources sometimes publish only a close for illiquid scrips; callers
        // pass closes for all three series rather than losing ATR entirely.
        $result = $this->indicators->atr($closes, $closes, $closes, 14);

        $this->assertNotNull($result[14]);
        $this->assertGreaterThan(0, $result[14]);
    }

    public function test_trend_is_bullish_when_price_leads_every_average(): void
    {
        $verdict = $this->indicators->classifyTrend(
            close: 120.0,
            sma20: 110.0,
            sma50: 105.0,
            sma200: 100.0,
            rsi: 62.0,
            macdHistogram: 1.5,
        );

        $this->assertSame('bullish', $verdict['trend']);
        $this->assertGreaterThan(50, $verdict['strength']);
    }

    public function test_trend_is_bearish_when_price_trails_every_average(): void
    {
        $verdict = $this->indicators->classifyTrend(
            close: 80.0,
            sma20: 90.0,
            sma50: 100.0,
            sma200: 110.0,
            rsi: 38.0,
            macdHistogram: -1.5,
        );

        $this->assertSame('bearish', $verdict['trend']);
    }

    public function test_trend_is_neutral_when_nothing_is_known(): void
    {
        $verdict = $this->indicators->classifyTrend(null, null, null, null, null, null);

        $this->assertSame('neutral', $verdict['trend']);
        $this->assertSame(0.0, $verdict['strength']);
    }

    public function test_trend_strength_never_exceeds_one_hundred(): void
    {
        $verdict = $this->indicators->classifyTrend(1000.0, 1.0, 1.0, 1.0, 90.0, 50.0);

        $this->assertLessThanOrEqual(100.0, $verdict['strength']);
    }
}
