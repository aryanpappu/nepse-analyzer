<?php

declare(strict_types=1);

namespace App\Services\Playbook;

use App\Enums\InvestorProfile;
use App\Enums\VerdictGrade;

/**
 * Scores a scrip for one investor profile, deterministically.
 *
 * No LLM call, no network: everything comes from bars and ratios already in the
 * database, so the whole market can be re-scored for all three profiles in a
 * couple of seconds after each scrape and it costs nothing. The AI analysis
 * still exists for the narrative — this engine is the part that has to run over
 * every scrip, every day, and be reproducible when you ask why.
 *
 * Every bucket returns null when the data to judge it is missing, and the
 * missing weight is redistributed across the buckets that did produce a score.
 * Penalising a scrip for having no filed fundamentals would just rank the
 * best-documented companies to the bottom.
 */
final class PlaybookEngine
{
    public function evaluate(PlaybookInputs $inputs, InvestorProfile $profile): ?PlaybookVerdict
    {
        if ($inputs->price <= 0.0) {
            return null;
        }

        // A momentum read off 12 bars is noise dressed as a signal.
        if ($inputs->sessions < $profile->minHistorySessions()) {
            return null;
        }

        /** @var array<string, float|null> $raw */
        $raw = [];
        /** @var list<array{bucket: string, text: string, effect: string}> $reasons */
        $reasons = [];

        $raw['fundamentals'] = $this->scoreFundamentals($inputs, $profile, $reasons);
        $raw['valuation'] = $this->scoreValuation($inputs, $profile, $reasons);
        $raw['trend'] = $this->scoreTrend($inputs, $profile, $reasons);
        $raw['momentum'] = $this->scoreMomentum($inputs, $profile, $reasons);
        $raw['volume'] = $this->scoreVolume($inputs, $profile, $reasons);

        $scores = array_filter($raw, static fn (?float $v): bool => $v !== null);

        if ($scores === []) {
            return null;
        }

        $score = $this->weightedScore($scores, $profile->weights());
        $grade = VerdictGrade::fromScore($score);
        $zone = $this->buildZone($inputs, $profile, $grade);

        return new PlaybookVerdict(
            profile: $profile,
            grade: $grade,
            score: $score,
            referencePrice: round($inputs->price, 2),
            entryLow: $zone['entry_low'],
            entryHigh: $zone['entry_high'],
            stopLoss: $zone['stop_loss'],
            target1: $zone['target_1'],
            target2: $zone['target_2'],
            riskReward: $zone['risk_reward'],
            scores: $scores,
            reasons: $reasons,
            summary: $this->summarize($inputs, $profile, $grade, $zone),
        );
    }

    /**
     * Weighted mean over the buckets that produced a score, with the weights
     * of the absent buckets spread proportionally over the present ones.
     *
     * @param  array<string, float>  $scores
     * @param  array<string, float>  $weights
     */
    private function weightedScore(array $scores, array $weights): float
    {
        $available = array_sum(array_intersect_key($weights, $scores));

        if ($available <= 0.0) {
            // Every weighted bucket is missing; fall back to a plain mean so a
            // zero-weight bucket still beats returning nothing.
            return round(array_sum($scores) / count($scores), 2);
        }

        $total = 0.0;

        foreach ($scores as $bucket => $value) {
            $total += $value * (($weights[$bucket] ?? 0.0) / $available);
        }

        return round(max(0.0, min(100.0, $total)), 2);
    }

    // ------------------------------------------------------------------
    // Score buckets
    // ------------------------------------------------------------------

    /**
     * @param  list<array{bucket: string, text: string, effect: string}>  $reasons
     */
    private function scoreFundamentals(PlaybookInputs $inputs, InvestorProfile $profile, array &$reasons): ?float
    {
        $f = $inputs->fundamental;

        if ($f === null) {
            if ($profile === InvestorProfile::LongTerm) {
                $reasons[] = $this->reason('fundamentals', 'No filed fundamentals on record — '
                    .'run nepse:fundamentals before underwriting this one.', 'neutral');
            }

            return null;
        }

        $parts = [];

        $roe = $f->roe === null ? null : (float) $f->roe;

        if ($roe !== null) {
            $parts[] = $this->curve($roe, [[0.0, 0.0], [5.0, 30.0], [10.0, 55.0], [15.0, 78.0], [20.0, 92.0], [30.0, 100.0]]);
            $reasons[] = $this->reason('fundamentals', sprintf('ROE %.1f%%', $roe),
                $roe >= 15.0 ? 'positive' : ($roe < 8.0 ? 'negative' : 'neutral'));
        }

        $eps = $f->eps === null ? null : (float) $f->eps;

        if ($eps !== null) {
            // EPS is judged against paid-up value (Rs 100 in Nepal), so a Rs 30
            // EPS is a 30% return on the par unit — the local yardstick.
            $parts[] = $this->curve($eps, [[-5.0, 0.0], [0.0, 20.0], [10.0, 50.0], [20.0, 75.0], [35.0, 92.0], [60.0, 100.0]]);

            if ($eps <= 0.0) {
                $reasons[] = $this->reason('fundamentals', sprintf('Loss-making: EPS Rs %.2f', $eps), 'negative');
            }
        }

        $yield = $f->dividend_yield === null ? null : (float) $f->dividend_yield;

        if ($yield !== null && $yield > 0.0) {
            $parts[] = $this->curve($yield, [[0.0, 25.0], [2.0, 45.0], [4.0, 65.0], [6.0, 82.0], [9.0, 100.0]]);
            $reasons[] = $this->reason('fundamentals', sprintf('Dividend yield %.2f%%', $yield),
                $yield >= 4.0 ? 'positive' : 'neutral');
        }

        // Bank and finance specific: non-performing loans are the fastest way to
        // find out a profitable-looking lender is not one.
        $npl = $f->npl_percent === null ? null : (float) $f->npl_percent;

        if ($npl !== null) {
            $parts[] = $this->curve($npl, [[0.0, 100.0], [1.5, 85.0], [3.0, 60.0], [5.0, 30.0], [8.0, 5.0]]);

            if ($npl >= 4.0) {
                $reasons[] = $this->reason('fundamentals', sprintf('NPL %.2f%% — asset quality stress', $npl), 'negative');
            }
        }

        if ($parts === []) {
            return null;
        }

        return array_sum($parts) / count($parts);
    }

    /**
     * @param  list<array{bucket: string, text: string, effect: string}>  $reasons
     */
    private function scoreValuation(PlaybookInputs $inputs, InvestorProfile $profile, array &$reasons): ?float
    {
        if ($profile === InvestorProfile::Momentum) {
            // Momentum does not pay for cheapness and will not wait for it.
            return null;
        }

        $f = $inputs->fundamental;

        if ($f === null) {
            return null;
        }

        $parts = [];

        $pe = $f->pe_ratio === null ? null : (float) $f->pe_ratio;

        if ($pe !== null && $pe > 0.0) {
            $parts[] = $this->curve($pe, [[5.0, 100.0], [12.0, 88.0], [18.0, 68.0], [25.0, 45.0], [35.0, 20.0], [50.0, 5.0]]);
            $reasons[] = $this->reason('valuation', sprintf('P/E %.1f', $pe),
                $pe <= 18.0 ? 'positive' : ($pe >= 30.0 ? 'negative' : 'neutral'));
        }

        $pb = $f->pb_ratio === null ? null : (float) $f->pb_ratio;

        if ($pb !== null && $pb > 0.0) {
            $parts[] = $this->curve($pb, [[0.6, 100.0], [1.0, 90.0], [1.6, 70.0], [2.5, 48.0], [4.0, 22.0], [7.0, 5.0]]);
            $reasons[] = $this->reason('valuation', sprintf('P/B %.2f', $pb),
                $pb <= 1.5 ? 'positive' : ($pb >= 3.0 ? 'negative' : 'neutral'));
        }

        if ($parts === []) {
            return null;
        }

        return array_sum($parts) / count($parts);
    }

    /**
     * @param  list<array{bucket: string, text: string, effect: string}>  $reasons
     */
    private function scoreTrend(PlaybookInputs $inputs, InvestorProfile $profile, array &$reasons): ?float
    {
        $i = $inputs->indicator;

        if ($i === null) {
            return null;
        }

        $price = $inputs->price;
        $sma20 = $i->sma_20 === null ? null : (float) $i->sma_20;
        $sma50 = $i->sma_50 === null ? null : (float) $i->sma_50;
        $sma200 = $i->sma_200 === null ? null : (float) $i->sma_200;

        $parts = [];

        // Stacked moving averages are the cleanest statement that a trend
        // exists and is being maintained at every timescale.
        if ($sma20 !== null && $sma50 !== null && $sma200 !== null) {
            $stacked = $sma20 > $sma50 && $sma50 > $sma200;
            $inverted = $sma20 < $sma50 && $sma50 < $sma200;

            $parts[] = $stacked ? 95.0 : ($inverted ? 12.0 : 50.0);

            if ($stacked) {
                $reasons[] = $this->reason('trend', 'Moving averages stacked bullish (20 > 50 > 200)', 'positive');
            } elseif ($inverted) {
                $reasons[] = $this->reason('trend', 'Moving averages stacked bearish (20 < 50 < 200)', 'negative');
            }
        }

        if ($sma200 !== null && $sma200 > 0.0) {
            $ratio = $price / $sma200;

            if ($profile === InvestorProfile::LongTerm) {
                // A long-term buyer wants a good business near or modestly below
                // its long trend. Far above is late; far below needs a reason,
                // so it scores mid rather than best.
                $parts[] = $this->curve($ratio, [[0.70, 45.0], [0.88, 78.0], [1.00, 88.0], [1.15, 70.0], [1.40, 40.0], [1.80, 15.0]]);
            } else {
                // Shorter horizons should simply not be long below the 200 DMA.
                $parts[] = $this->curve($ratio, [[0.85, 10.0], [0.97, 40.0], [1.02, 75.0], [1.15, 92.0], [1.45, 70.0], [1.80, 40.0]]);
            }

            $reasons[] = $this->reason('trend', sprintf('%s the 200-day average (Rs %s)',
                $price >= $sma200 ? 'Above' : 'Below', number_format($sma200, 2)),
                $price >= $sma200 ? 'positive' : 'negative');
        }

        if ($sma50 !== null && $sma50 > 0.0 && $profile !== InvestorProfile::LongTerm) {
            $parts[] = $this->curve($price / $sma50, [[0.90, 20.0], [0.98, 50.0], [1.03, 85.0], [1.12, 92.0], [1.25, 60.0]]);
        }

        $strength = $i->trend_strength === null ? null : (float) $i->trend_strength;

        if ($strength !== null) {
            // Stored 0-100 by the indicator service; a weak trend is a coin flip
            // for every profile, so it lands mid rather than low.
            $parts[] = 30.0 + ($strength * 0.6);
        }

        if ($parts === []) {
            return null;
        }

        return array_sum($parts) / count($parts);
    }

    /**
     * @param  list<array{bucket: string, text: string, effect: string}>  $reasons
     */
    private function scoreMomentum(PlaybookInputs $inputs, InvestorProfile $profile, array &$reasons): ?float
    {
        $i = $inputs->indicator;

        if ($i === null) {
            return null;
        }

        $parts = [];
        $rsi = $i->rsi_14 === null ? null : (float) $i->rsi_14;

        if ($rsi !== null) {
            [$low, $high] = $profile->buyableRsi();
            $parts[] = $this->bandScore($rsi, $low, $high);

            $effect = $rsi >= $low && $rsi <= $high ? 'positive' : 'negative';
            $note = match (true) {
                $rsi >= 70.0 => 'overbought',
                $rsi <= 30.0 => 'oversold',
                default => 'neutral',
            };
            $reasons[] = $this->reason('momentum', sprintf('RSI %.1f (%s)', $rsi, $note), $effect);
        }

        $macd = $i->macd === null ? null : (float) $i->macd;
        $signal = $i->macd_signal === null ? null : (float) $i->macd_signal;
        $hist = $i->macd_histogram === null ? null : (float) $i->macd_histogram;

        if ($macd !== null && $signal !== null) {
            $above = $macd > $signal;
            $rising = $hist !== null && $hist > 0.0;

            $parts[] = match (true) {
                $above && $rising => 90.0,
                $above => 70.0,
                $rising => 55.0,
                default => 22.0,
            };

            $reasons[] = $this->reason('momentum',
                $above ? 'MACD above its signal line' : 'MACD below its signal line',
                $above ? 'positive' : 'negative');
        }

        $position = $inputs->rangePosition20();

        if ($position !== null) {
            $parts[] = match ($profile) {
                // Momentum wants the top of the range — that is the breakout.
                InvestorProfile::Momentum => $this->curve($position, [[0.3, 10.0], [0.6, 45.0], [0.8, 80.0], [0.95, 100.0]]),
                // A swing entry is bought in the lower half and sold in the upper.
                InvestorProfile::Swing => $this->curve($position, [[0.0, 70.0], [0.25, 90.0], [0.5, 70.0], [0.75, 40.0], [1.0, 15.0]]),
                // Long term barely cares where in a 20-day range it buys.
                InvestorProfile::LongTerm => 50.0 + ((1.0 - $position) * 20.0),
            };

            if ($profile === InvestorProfile::Momentum && $position >= 0.9) {
                $reasons[] = $this->reason('momentum', 'Closing at the top of its 20-day range', 'positive');
            }

            if ($profile === InvestorProfile::Swing && $position <= 0.25) {
                $reasons[] = $this->reason('momentum', 'Near the bottom of its 20-day range', 'positive');
            }
        }

        $drawdown = $inputs->drawdownFrom52wHigh();

        if ($drawdown !== null && $profile !== InvestorProfile::LongTerm) {
            // A scrip 60% off its high is not a swing setup, whatever the RSI says.
            $parts[] = $this->curve($drawdown, [[0.0, 95.0], [0.10, 85.0], [0.25, 60.0], [0.45, 30.0], [0.70, 8.0]]);
        }

        if ($parts === []) {
            return null;
        }

        return array_sum($parts) / count($parts);
    }

    /**
     * @param  list<array{bucket: string, text: string, effect: string}>  $reasons
     */
    private function scoreVolume(PlaybookInputs $inputs, InvestorProfile $profile, array &$reasons): ?float
    {
        $ratio = $inputs->volumeRatio();

        if ($ratio === null) {
            return null;
        }

        $score = match ($profile) {
            // A breakout on average volume is a trap; conviction shows up here.
            InvestorProfile::Momentum => $this->curve($ratio, [[0.4, 5.0], [0.8, 25.0], [1.2, 55.0], [1.8, 85.0], [3.0, 100.0]]),
            InvestorProfile::Swing => $this->curve($ratio, [[0.3, 15.0], [0.7, 45.0], [1.0, 70.0], [1.8, 90.0], [4.0, 75.0]]),
            // For a multi-year hold, volume is only a liquidity check: can you
            // get out at all?
            InvestorProfile::LongTerm => $this->curve($ratio, [[0.1, 25.0], [0.5, 55.0], [1.0, 70.0], [2.5, 70.0]]),
        };

        if ($profile === InvestorProfile::Momentum && $ratio >= 1.8) {
            $reasons[] = $this->reason('volume', sprintf('Volume %.1fx its 20-day average', $ratio), 'positive');
        }

        if ($ratio <= 0.35) {
            $reasons[] = $this->reason('volume', sprintf('Thin: volume %.2fx average — expect slippage', $ratio), 'negative');
        }

        return $score;
    }

    // ------------------------------------------------------------------
    // Trade geometry
    // ------------------------------------------------------------------

    /**
     * Entry band, stop and targets for this profile.
     *
     * The stop is placed off ATR so it respects how much this particular scrip
     * actually moves, then clamped to the profile's own loss tolerance — an ATR
     * stop on an illiquid scrip can otherwise sit 30% away and stop being a stop.
     *
     * @return array{entry_low: ?float, entry_high: ?float, stop_loss: ?float, target_1: ?float, target_2: ?float, risk_reward: ?float}
     */
    private function buildZone(PlaybookInputs $inputs, InvestorProfile $profile, VerdictGrade $grade): array
    {
        $price = $inputs->price;
        $i = $inputs->indicator;
        $atr = $inputs->atr();

        $sma20 = $i?->sma_20 === null ? null : (float) $i->sma_20;
        $sma50 = $i?->sma_50 === null ? null : (float) $i->sma_50;
        $sma200 = $i?->sma_200 === null ? null : (float) $i->sma_200;
        $bbLower = $i?->bollinger_lower === null ? null : (float) $i->bollinger_lower;
        $bbUpper = $i?->bollinger_upper === null ? null : (float) $i->bollinger_upper;

        [$entryLow, $entryHigh] = match ($profile) {
            // Long term accumulates between the long trend and a little above
            // spot — it does not need a precise entry, it needs a sane one.
            InvestorProfile::LongTerm => [
                min(array_filter([$price * 0.92, $sma200], static fn (?float $v): bool => $v !== null && $v > 0.0)),
                $price * 1.02,
            ],
            // Swing buys the lower band / 20 DMA area.
            InvestorProfile::Swing => [
                min(array_filter([$price * 0.97, $bbLower], static fn (?float $v): bool => $v !== null && $v > 0.0)),
                max($price * 1.005, $sma20 ?? $price),
            ],
            // Momentum buys the breakout: at spot, up to just through the
            // 20-day high. Chasing further than that is where the edge dies.
            InvestorProfile::Momentum => [
                $price * 0.99,
                max($price * 1.01, ($inputs->high20 ?? $price) * 1.01),
            ],
        };

        $entryLow = round(max(0.01, (float) $entryLow), 2);
        $entryHigh = round(max($entryLow + 0.01, (float) $entryHigh), 2);
        $mid = ($entryLow + $entryHigh) / 2;
        $maxLoss = $profile->maxStopLoss();

        // The entry band cannot be wider than the risk budget it is traded on.
        // A momentum band 7% wide with a 3% stop is incoherent: fill at the top
        // and the stop is already behind you. Narrowing it around the midpoint
        // keeps the band, the stop and the profile's loss tolerance consistent,
        // and leaves headroom for the stop to sit below the entry floor.
        $maxHalfWidth = $mid * $maxLoss * 0.6;

        if ((($entryHigh - $entryLow) / 2) > $maxHalfWidth) {
            $entryLow = round($mid - $maxHalfWidth, 2);
            $entryHigh = round($mid + $maxHalfWidth, 2);
        }

        $atrStop = $atr !== null && $atr > 0.0 ? $mid - ($atr * $profile->atrStopMultiple()) : null;
        $floorStop = $mid * (1 - $maxLoss);

        // Never wider than the profile tolerates, never tighter than half of it
        // — a 0.5% stop on a scrip with a 3% daily range is just a donation.
        $stop = $atrStop === null
            ? $floorStop
            : min(max($atrStop, $floorStop), $mid * (1 - ($maxLoss / 2)));

        [$band1, $band2] = $profile->targetBands();
        $target1 = $mid * (1 + $band1);
        $target2 = $mid * (1 + $band2);

        // A swing target that sits beyond the upper Bollinger band is asking the
        // band to break; pull the first target back to it when it is closer.
        if ($profile === InvestorProfile::Swing && $bbUpper !== null && $bbUpper > $mid) {
            $target1 = min($target1, max($bbUpper, $mid * 1.04));
        }

        if ($profile === InvestorProfile::LongTerm && $sma50 !== null && $sma50 > $price * 1.3) {
            // Deep-value case: the 50 DMA is far overhead, so use it as the
            // first realistic destination instead of a flat 25%.
            $target1 = min($target1, $sma50);
        }

        // A stop must sit below the *bottom* of the entry band, not below its
        // midpoint: buying the low of the zone and being stopped out on the same
        // bar is not a plan. Momentum zones are narrow enough for the ATR clamp
        // to land inside the band, so this is enforced rather than assumed.
        $stop = min($stop, $entryLow * 0.995);
        $stop = round(max(0.01, $stop), 2);

        // Pulling the first target back to a nearby band keeps it realistic, but
        // not so close that the trade cannot pay for its own risk.
        $target1 = max($target1, $mid * 1.03);
        $target1 = round($target1, 2);
        $target2 = round(max($target2, $target1 * 1.02), 2);

        $risk = $mid - $stop;
        $reward = $target1 - $mid;
        $riskReward = $risk > 0.0 ? round($reward / $risk, 2) : null;

        // An Avoid still gets levels — you need to know what would change your
        // mind — but the zone is not presented as a live entry.
        return [
            'entry_low' => $entryLow,
            'entry_high' => $entryHigh,
            'stop_loss' => $stop,
            'target_1' => $target1,
            'target_2' => $target2,
            'risk_reward' => $riskReward,
        ];
    }

    /**
     * @param  array{entry_low: ?float, entry_high: ?float, stop_loss: ?float, target_1: ?float, target_2: ?float, risk_reward: ?float}  $zone
     */
    private function summarize(PlaybookInputs $inputs, InvestorProfile $profile, VerdictGrade $grade, array $zone): string
    {
        $symbol = $inputs->stock->symbol;
        $inZone = $zone['entry_low'] !== null && $zone['entry_high'] !== null
            && $inputs->price >= $zone['entry_low'] && $inputs->price <= $zone['entry_high'];

        if (! $grade->isActionable()) {
            return sprintf(
                '%s: %s for a %s. %s',
                $symbol,
                $grade->label(),
                strtolower($profile->label()),
                $inZone
                    // Price is where you would want it; the evidence is not.
                    ? sprintf('Price is in the Rs %s–%s area but the setup does not earn a buy yet.',
                        number_format((float) $zone['entry_low'], 2),
                        number_format((float) $zone['entry_high'], 2))
                    : sprintf('Revisit if it trades into Rs %s–%s.',
                        number_format((float) $zone['entry_low'], 2),
                        number_format((float) $zone['entry_high'], 2)),
            );
        }

        return sprintf(
            '%s: %s for a %s. %s Stop Rs %s, first target Rs %s, hold %s.',
            $symbol,
            $grade->label(),
            strtolower($profile->label()),
            $inZone
                ? 'Price is inside the buy zone now.'
                : sprintf('Wait for Rs %s–%s.', number_format((float) $zone['entry_low'], 2), number_format((float) $zone['entry_high'], 2)),
            number_format((float) $zone['stop_loss'], 2),
            number_format((float) $zone['target_1'], 2),
            $profile->holdLabel(),
        );
    }

    // ------------------------------------------------------------------
    // Scoring helpers
    // ------------------------------------------------------------------

    /**
     * Piecewise-linear interpolation over [input, score] knots.
     *
     * Preferred over a pile of if/else thresholds because a scrip one basis
     * point either side of a threshold should not jump 20 points of score.
     *
     * @param  list<array{float, float}>  $knots  Ascending by input.
     */
    private function curve(float $value, array $knots): float
    {
        $first = $knots[0];
        $last = $knots[count($knots) - 1];

        if ($value <= $first[0]) {
            return $first[1];
        }

        if ($value >= $last[0]) {
            return $last[1];
        }

        foreach ($knots as $index => [$x, $y]) {
            if ($index === 0) {
                continue;
            }

            [$prevX, $prevY] = $knots[$index - 1];

            if ($value <= $x) {
                $span = $x - $prevX;

                return $span <= 0.0 ? $y : $prevY + (($value - $prevX) / $span) * ($y - $prevY);
            }
        }

        return $last[1];
    }

    /**
     * 100 at the centre of [low, high], tapering to 0 one band-width outside.
     */
    private function bandScore(float $value, float $low, float $high): float
    {
        if ($high <= $low) {
            return 50.0;
        }

        $centre = ($low + $high) / 2;
        $halfWidth = ($high - $low) / 2;
        $distance = abs($value - $centre) / $halfWidth;

        return $distance <= 1.0
            ? 100.0 - ($distance * 25.0)   // inside the band: 100 down to 75
            : max(0.0, 75.0 - (($distance - 1.0) * 75.0));
    }

    /**
     * @return array{bucket: string, text: string, effect: string}
     */
    private function reason(string $bucket, string $text, string $effect): array
    {
        return ['bucket' => $bucket, 'text' => $text, 'effect' => $effect];
    }
}
