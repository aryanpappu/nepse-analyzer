<?php

declare(strict_types=1);

namespace App\Services\Indicators;

/**
 * Pure technical-indicator maths. No database, no framework — every method
 * takes an ordered series and returns a series or scalar, which keeps it
 * trivially unit-testable.
 *
 * Convention used throughout: input series are ordered OLDEST FIRST, and every
 * returned series is aligned to the input, left-padded with nulls for the
 * periods where the indicator is not yet defined. Callers can therefore zip
 * closes[i] with rsi[i] without tracking offsets.
 */
final class TechnicalIndicatorService
{
    /**
     * Simple moving average.
     *
     * @param  list<float>  $values
     * @return list<float|null>
     */
    public function sma(array $values, int $period): array
    {
        $count = count($values);
        $out = array_fill(0, $count, null);

        if ($period <= 0 || $count < $period) {
            return $out;
        }

        // Running sum, so the whole series costs O(n) rather than O(n*period).
        $sum = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $sum += $values[$i];

            if ($i >= $period) {
                $sum -= $values[$i - $period];
            }

            if ($i >= $period - 1) {
                $out[$i] = (float) ($sum / $period);
            }
        }

        return $out;
    }

    /**
     * Exponential moving average. Seeded with the SMA of the first `period`
     * values, which is the standard convention and avoids the long warm-up
     * bias you get from seeding with the first value alone.
     *
     * @param  list<float>  $values
     * @return list<float|null>
     */
    public function ema(array $values, int $period): array
    {
        $count = count($values);
        $out = array_fill(0, $count, null);

        if ($period <= 0 || $count < $period) {
            return $out;
        }

        $multiplier = 2 / ($period + 1);

        $seed = (float) (array_sum(array_slice($values, 0, $period)) / $period);
        $out[$period - 1] = $seed;
        $previous = $seed;

        for ($i = $period; $i < $count; $i++) {
            $previous = ($values[$i] - $previous) * $multiplier + $previous;
            $out[$i] = $previous;
        }

        return $out;
    }

    /**
     * Wilder's RSI. Uses Wilder smoothing (not a plain SMA of gains/losses),
     * which is what charting platforms and, more importantly, what Nepali
     * retail traders' tools display.
     *
     * @param  list<float>  $values
     * @return list<float|null>
     */
    public function rsi(array $values, int $period = 14): array
    {
        $count = count($values);
        $out = array_fill(0, $count, null);

        if ($period <= 0 || $count <= $period) {
            return $out;
        }

        $gains = 0.0;
        $losses = 0.0;

        // Seed period: simple average of the first `period` changes.
        for ($i = 1; $i <= $period; $i++) {
            $change = $values[$i] - $values[$i - 1];
            $gains += max($change, 0.0);
            $losses += max(-$change, 0.0);
        }

        $avgGain = $gains / $period;
        $avgLoss = $losses / $period;
        $out[$period] = $this->rsiFrom($avgGain, $avgLoss);

        for ($i = $period + 1; $i < $count; $i++) {
            $change = $values[$i] - $values[$i - 1];

            $avgGain = ($avgGain * ($period - 1) + max($change, 0.0)) / $period;
            $avgLoss = ($avgLoss * ($period - 1) + max(-$change, 0.0)) / $period;

            $out[$i] = $this->rsiFrom($avgGain, $avgLoss);
        }

        return $out;
    }

    private function rsiFrom(float $avgGain, float $avgLoss): float
    {
        // A stock that only rose during the window has no losses; RSI is 100 by
        // definition. Guarding here avoids a division by zero.
        if ($avgLoss < 1e-12) {
            return $avgGain < 1e-12 ? 50.0 : 100.0;
        }

        $rs = $avgGain / $avgLoss;

        return round(100 - (100 / (1 + $rs)), 4);
    }

    /**
     * MACD line, signal line and histogram.
     *
     * @param  list<float>  $values
     * @return array{macd: list<float|null>, signal: list<float|null>, histogram: list<float|null>}
     */
    public function macd(array $values, int $fast = 12, int $slow = 26, int $signalPeriod = 9): array
    {
        $count = count($values);
        $emaFast = $this->ema($values, $fast);
        $emaSlow = $this->ema($values, $slow);

        $macd = array_fill(0, $count, null);

        for ($i = 0; $i < $count; $i++) {
            if ($emaFast[$i] !== null && $emaSlow[$i] !== null) {
                $macd[$i] = $emaFast[$i] - $emaSlow[$i];
            }
        }

        // The signal line is an EMA of the MACD line, which only exists from the
        // slow period onward. Compact to the defined part, smooth, then re-pad
        // so the result stays aligned with the input series.
        $definedFrom = null;

        foreach ($macd as $i => $value) {
            if ($value !== null) {
                $definedFrom = $i;
                break;
            }
        }

        $signal = array_fill(0, $count, null);
        $histogram = array_fill(0, $count, null);

        if ($definedFrom === null) {
            return ['macd' => $macd, 'signal' => $signal, 'histogram' => $histogram];
        }

        /** @var list<float> $compact */
        $compact = array_values(array_slice($macd, $definedFrom));
        $signalCompact = $this->ema($compact, $signalPeriod);

        foreach ($signalCompact as $offset => $value) {
            $index = $definedFrom + $offset;
            $signal[$index] = $value;

            if ($value !== null && $macd[$index] !== null) {
                $histogram[$index] = $macd[$index] - $value;
            }
        }

        return ['macd' => $macd, 'signal' => $signal, 'histogram' => $histogram];
    }

    /**
     * Bollinger Bands using a population standard deviation, matching the
     * original Bollinger definition.
     *
     * @param  list<float>  $values
     * @return array{upper: list<float|null>, middle: list<float|null>, lower: list<float|null>}
     */
    public function bollingerBands(array $values, int $period = 20, float $deviations = 2.0): array
    {
        $count = count($values);
        $middle = $this->sma($values, $period);
        $upper = array_fill(0, $count, null);
        $lower = array_fill(0, $count, null);

        for ($i = 0; $i < $count; $i++) {
            if ($middle[$i] === null) {
                continue;
            }

            $window = array_slice($values, $i - $period + 1, $period);
            $mean = $middle[$i];

            $variance = 0.0;
            foreach ($window as $value) {
                $variance += ($value - $mean) ** 2;
            }
            $stdDev = sqrt($variance / $period);

            $upper[$i] = $mean + $deviations * $stdDev;
            $lower[$i] = $mean - $deviations * $stdDev;
        }

        return ['upper' => $upper, 'middle' => $middle, 'lower' => $lower];
    }

    /**
     * Average True Range (Wilder). Needs high/low/close; when a source only
     * gives closes, callers should pass closes for all three, which degrades
     * ATR to an average absolute change rather than producing nonsense.
     *
     * @param  list<float>  $highs
     * @param  list<float>  $lows
     * @param  list<float>  $closes
     * @return list<float|null>
     */
    public function atr(array $highs, array $lows, array $closes, int $period = 14): array
    {
        $count = count($closes);
        $out = array_fill(0, $count, null);

        if ($count <= $period || count($highs) !== $count || count($lows) !== $count) {
            return $out;
        }

        $trueRanges = [0.0];

        for ($i = 1; $i < $count; $i++) {
            $trueRanges[$i] = max(
                $highs[$i] - $lows[$i],
                abs($highs[$i] - $closes[$i - 1]),
                abs($lows[$i] - $closes[$i - 1]),
            );
        }

        $atr = array_sum(array_slice($trueRanges, 1, $period)) / $period;
        $out[$period] = $atr;

        for ($i = $period + 1; $i < $count; $i++) {
            $atr = ($atr * ($period - 1) + $trueRanges[$i]) / $period;
            $out[$i] = $atr;
        }

        return $out;
    }

    /**
     * Collapse the latest indicator values into a single trend verdict.
     *
     * This is deliberately simple and explainable — it is shown to users and
     * fed to the model as a starting point, not treated as ground truth. Score
     * runs -100 (strongly bearish) to +100 (strongly bullish).
     *
     * @return array{trend: string, strength: float}
     */
    public function classifyTrend(
        ?float $close,
        ?float $sma20,
        ?float $sma50,
        ?float $sma200,
        ?float $rsi,
        ?float $macdHistogram,
    ): array {
        $score = 0.0;
        $weight = 0.0;

        // Price relative to each moving average: the classic trend filter.
        foreach ([[$sma20, 25.0], [$sma50, 25.0], [$sma200, 20.0]] as [$sma, $w]) {
            if ($close !== null && $sma !== null && $sma > 0) {
                $score += ($close > $sma ? 1 : -1) * $w;
                $weight += $w;
            }
        }

        // Golden / death cross between the medium and long averages.
        if ($sma50 !== null && $sma200 !== null) {
            $score += ($sma50 > $sma200 ? 1 : -1) * 15.0;
            $weight += 15.0;
        }

        // MACD histogram sign gives near-term momentum direction.
        if ($macdHistogram !== null) {
            $score += ($macdHistogram > 0 ? 1 : -1) * 15.0;
            $weight += 15.0;
        }

        // RSI contributes only at the extremes, where it is actually meaningful.
        if ($rsi !== null) {
            $rsiScore = match (true) {
                $rsi >= 70 => -5.0,  // overbought: a mild caution, not a reversal call
                $rsi <= 30 => 5.0,   // oversold: mild support
                $rsi >= 55 => 10.0,
                $rsi <= 45 => -10.0,
                default => 0.0,
            };
            $score += $rsiScore;
            $weight += 10.0;
        }

        if ($weight <= 0.0) {
            return ['trend' => 'neutral', 'strength' => 0.0];
        }

        $normalized = $score / $weight * 100;

        $trend = match (true) {
            $normalized >= 25 => 'bullish',
            $normalized <= -25 => 'bearish',
            default => 'neutral',
        };

        return [
            'trend' => $trend,
            'strength' => round(min(100, abs($normalized)), 2),
        ];
    }
}
