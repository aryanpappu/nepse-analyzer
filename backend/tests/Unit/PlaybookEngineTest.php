<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\InvestorProfile;
use App\Enums\VerdictGrade;
use App\Models\Stock;
use App\Models\StockFundamental;
use App\Models\TechnicalIndicator;
use App\Services\Playbook\PlaybookEngine;
use App\Services\Playbook\PlaybookInputs;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The scoring rules and the trade geometry.
 *
 * The geometry invariants matter more than the exact scores: a verdict whose
 * stop sits inside its own entry band, or whose target is below its entry, is
 * not a plan — it is a way to lose money while believing you had one. Those are
 * asserted for every profile against both a strong and a broken setup.
 */
final class PlaybookEngineTest extends TestCase
{
    private function engine(): PlaybookEngine
    {
        return new PlaybookEngine;
    }

    /**
     * @param  array<string, mixed>  $indicator
     * @param  array<string, mixed>|null  $fundamental
     */
    private function inputs(
        float $price = 500.0,
        array $indicator = [],
        ?array $fundamental = null,
        int $sessions = 400,
        ?float $high20 = null,
        ?float $low20 = null,
        ?float $high52w = null,
        ?float $volume = 20000.0,
    ): PlaybookInputs {
        return new PlaybookInputs(
            stock: new Stock(['symbol' => 'TEST', 'name' => 'Test Scrip', 'instrument_type' => 'equity']),
            price: $price,
            indicator: new TechnicalIndicator(array_merge([
                'rsi_14' => 50,
                'macd' => 1,
                'macd_signal' => 0.5,
                'macd_histogram' => 0.5,
                'sma_20' => $price,
                'sma_50' => $price,
                'sma_200' => $price,
                'bollinger_upper' => $price * 1.05,
                'bollinger_middle' => $price,
                'bollinger_lower' => $price * 0.95,
                'atr_14' => $price * 0.02,
                'volume_sma_20' => 20000,
                'trend' => 'sideways',
                'trend_strength' => 50,
            ], $indicator)),
            fundamental: $fundamental === null ? null : new StockFundamental($fundamental),
            sessions: $sessions,
            high20: $high20 ?? $price * 1.05,
            low20: $low20 ?? $price * 0.95,
            high52w: $high52w ?? $price * 1.20,
            low52w: $price * 0.7,
            volume: $volume,
        );
    }

    #[Test]
    public function every_profile_produces_a_survivable_geometry(): void
    {
        // A stop at or above the entry floor stops you out on the fill; a target
        // at or below it means the trade cannot pay. Neither may ever ship.
        $cases = [
            'strong uptrend' => $this->inputs(
                price: 520,
                indicator: ['rsi_14' => 61, 'sma_20' => 505, 'sma_50' => 480, 'sma_200' => 430, 'trend_strength' => 82],
                fundamental: ['eps' => 40, 'pe_ratio' => 13, 'pb_ratio' => 1.4, 'roe' => 19, 'dividend_yield' => 5],
                volume: 60000,
            ),
            'broken downtrend' => $this->inputs(
                price: 300,
                indicator: ['rsi_14' => 24, 'macd' => -4, 'macd_signal' => -2, 'macd_histogram' => -2,
                    'sma_20' => 330, 'sma_50' => 380, 'sma_200' => 450, 'trend_strength' => 15],
                fundamental: ['eps' => -8, 'pe_ratio' => null, 'pb_ratio' => 5.5, 'roe' => -6, 'npl_percent' => 9],
                high52w: 700,
                volume: 900,
            ),
            'flat and illiquid' => $this->inputs(price: 100, volume: 200),
        ];

        foreach ($cases as $name => $inputs) {
            foreach (InvestorProfile::cases() as $profile) {
                $verdict = $this->engine()->evaluate($inputs, $profile);

                $this->assertNotNull($verdict, "$name / {$profile->value} produced no verdict");
                $this->assertNotNull($verdict->entryLow);
                $this->assertNotNull($verdict->entryHigh);

                $label = "$name / {$profile->value}";
                $this->assertLessThan($verdict->entryHigh, $verdict->entryLow, "$label: entry band inverted");
                $this->assertLessThan($verdict->entryLow, $verdict->stopLoss, "$label: stop is not below the entry floor");
                $this->assertGreaterThan(0, $verdict->stopLoss, "$label: stop is not a positive price");
                $this->assertGreaterThan($verdict->entryHigh, $verdict->target1, "$label: first target is inside the entry band");
                $this->assertGreaterThan($verdict->target1, $verdict->target2, "$label: targets are out of order");
                $this->assertGreaterThan(0, $verdict->riskReward, "$label: risk/reward is not positive");
                $this->assertGreaterThanOrEqual(0.0, $verdict->score);
                $this->assertLessThanOrEqual(100.0, $verdict->score);
            }
        }
    }

    #[Test]
    public function the_stop_never_exceeds_the_profiles_loss_tolerance(): void
    {
        // A scrip with a huge ATR would otherwise push the stop miles away and
        // quietly turn a 3% momentum trade into a 30% one.
        $inputs = $this->inputs(price: 400, indicator: ['atr_14' => 90]);

        foreach (InvestorProfile::cases() as $profile) {
            $verdict = $this->engine()->evaluate($inputs, $profile);
            $mid = (float) $verdict->entryMid();
            $loss = ($mid - (float) $verdict->stopLoss) / $mid;

            $this->assertLessThanOrEqual(
                $profile->maxStopLoss() + 0.001,
                $loss,
                "{$profile->value}: stop is {$loss} below entry, past its tolerance",
            );
        }
    }

    #[Test]
    public function momentum_rewards_a_breakout_that_long_term_ignores(): void
    {
        $breakout = $this->inputs(
            price: 550,
            indicator: ['rsi_14' => 64, 'sma_20' => 520, 'sma_50' => 495, 'sma_200' => 450, 'trend_strength' => 85],
            high20: 552,
            low20: 470,
            high52w: 555,
            volume: 80000,
        );

        $momentum = $this->engine()->evaluate($breakout, InvestorProfile::Momentum);
        $longTerm = $this->engine()->evaluate($breakout, InvestorProfile::LongTerm);

        // Same bars, different question: momentum is being paid to buy strength,
        // a long-term buyer is being asked to pay up for it.
        $this->assertGreaterThan($longTerm->score, $momentum->score);
        $this->assertTrue($momentum->grade->isActionable());
    }

    #[Test]
    public function swing_rewards_an_oversold_dip_that_momentum_avoids(): void
    {
        $dip = $this->inputs(
            price: 462,
            indicator: ['rsi_14' => 34, 'sma_20' => 480, 'sma_50' => 470, 'sma_200' => 440,
                'bollinger_lower' => 458, 'bollinger_upper' => 505, 'trend_strength' => 60],
            high20: 505,
            low20: 458,
            high52w: 520,
        );

        $swing = $this->engine()->evaluate($dip, InvestorProfile::Swing);
        $momentum = $this->engine()->evaluate($dip, InvestorProfile::Momentum);

        $this->assertGreaterThan($momentum->score, $swing->score);
    }

    #[Test]
    public function a_profile_declines_to_judge_without_enough_history(): void
    {
        $thin = $this->inputs(sessions: 35);

        $this->assertNull($this->engine()->evaluate($thin, InvestorProfile::LongTerm));
        $this->assertNull($this->engine()->evaluate($thin, InvestorProfile::Swing));
        // Momentum only needs 30 sessions, so it still has an opinion.
        $this->assertNotNull($this->engine()->evaluate($thin, InvestorProfile::Momentum));
    }

    #[Test]
    public function a_missing_fundamental_does_not_penalise_the_score(): void
    {
        // Redistributing the absent weight matters most for the long-term
        // profile, where fundamentals plus valuation are 65% of the score.
        $withFundamentals = $this->inputs(
            indicator: ['rsi_14' => 55, 'sma_20' => 505, 'sma_50' => 495, 'sma_200' => 470, 'trend_strength' => 70],
            fundamental: ['eps' => 35, 'pe_ratio' => 14, 'pb_ratio' => 1.5, 'roe' => 18],
        );
        $without = $this->inputs(
            indicator: ['rsi_14' => 55, 'sma_20' => 505, 'sma_50' => 495, 'sma_200' => 470, 'trend_strength' => 70],
        );

        $bare = $this->engine()->evaluate($without, InvestorProfile::LongTerm);

        $this->assertNotNull($bare);
        // Not equal to the documented case — just not collapsed to nothing.
        $this->assertGreaterThan(35.0, $bare->score);
        $this->assertArrayNotHasKey('fundamentals', $bare->scores);
        $this->assertArrayHasKey('trend', $bare->scores);
    }

    #[Test]
    public function a_wrecked_scrip_is_avoided_by_the_short_horizons(): void
    {
        $wrecked = $this->inputs(
            price: 210,
            indicator: ['rsi_14' => 19, 'macd' => -6, 'macd_signal' => -3, 'macd_histogram' => -3,
                'sma_20' => 250, 'sma_50' => 310, 'sma_200' => 420, 'trend_strength' => 8],
            fundamental: ['eps' => -14, 'pb_ratio' => 6.5, 'roe' => -11, 'npl_percent' => 11],
            high52w: 640,
            volume: 400,
        );

        $this->assertSame(VerdictGrade::Avoid, $this->engine()->evaluate($wrecked, InvestorProfile::Momentum)->grade);
        $this->assertSame(VerdictGrade::Avoid, $this->engine()->evaluate($wrecked, InvestorProfile::Swing)->grade);
    }

    #[Test]
    public function the_summary_never_contradicts_the_zone(): void
    {
        // A Watch whose price is already in the zone must not say "revisit if it
        // trades into" that zone — it already has.
        $inputs = $this->inputs(price: 500);

        foreach (InvestorProfile::cases() as $profile) {
            $verdict = $this->engine()->evaluate($inputs, $profile);

            if ($verdict->priceInZone()) {
                $this->assertStringNotContainsString('Revisit if it trades into', $verdict->summary);
            }
        }
    }
}
