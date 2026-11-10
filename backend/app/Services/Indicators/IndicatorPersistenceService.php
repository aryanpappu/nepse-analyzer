<?php

declare(strict_types=1);

namespace App\Services\Indicators;

use App\Models\Stock;
use App\Models\TechnicalIndicator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Bridges the pure indicator maths to the database: loads a stock's close
 * history, computes every indicator series, and upserts the rows.
 */
final class IndicatorPersistenceService
{
    /**
     * Longest lookback we support (SMA 200) plus headroom so the 200-day
     * average is defined for the most recent rows rather than all-null.
     */
    private const HISTORY_WINDOW = 400;

    public function __construct(
        private readonly TechnicalIndicatorService $indicators,
    ) {}

    /**
     * Recalculate indicators for one stock.
     *
     * @param  int|null  $onlyLastDays  Limit the rows written (the maths still
     *                                  uses full history). Null rewrites all.
     * @return int Number of indicator rows written.
     */
    public function recalculate(Stock $stock, ?int $onlyLastDays = null): int
    {
        // Oldest-first is what every indicator in the service expects.
        $prices = $stock->prices()
            ->orderByDesc('trade_date')
            ->limit(self::HISTORY_WINDOW)
            ->get(['trade_date', 'open', 'high', 'low', 'close', 'volume'])
            ->sortBy('trade_date')
            ->values();

        $minHistory = (int) config('nepse.analysis.min_history_days', 30);

        if ($prices->count() < $minHistory) {
            Log::channel('analysis')->debug('Skipping indicators, not enough history', [
                'symbol' => $stock->symbol,
                'rows' => $prices->count(),
                'required' => $minHistory,
            ]);

            return 0;
        }

        $closes = $prices->map(static fn ($p): float => (float) $p->close)->all();

        // Some sources publish only the closing price for thinly traded scrips.
        // Falling back to the close keeps ATR defined instead of null-ing the
        // whole series; it degrades to mean absolute change, which is honest.
        $highs = $prices->map(static fn ($p): float => (float) ($p->high ?? $p->close))->all();
        $lows = $prices->map(static fn ($p): float => (float) ($p->low ?? $p->close))->all();
        $volumes = $prices->map(static fn ($p): float => (float) $p->volume)->all();

        $rsi = $this->indicators->rsi($closes, 14);
        $macd = $this->indicators->macd($closes);
        $sma20 = $this->indicators->sma($closes, 20);
        $sma50 = $this->indicators->sma($closes, 50);
        $sma200 = $this->indicators->sma($closes, 200);
        $ema12 = $this->indicators->ema($closes, 12);
        $ema26 = $this->indicators->ema($closes, 26);
        $bands = $this->indicators->bollingerBands($closes, 20);
        $atr = $this->indicators->atr($highs, $lows, $closes, 14);
        $volumeSma = $this->indicators->sma($volumes, 20);

        $rows = [];
        $now = Carbon::now();
        $startIndex = 0;

        if ($onlyLastDays !== null && $onlyLastDays > 0) {
            $startIndex = max(0, $prices->count() - $onlyLastDays);
        }

        for ($i = $startIndex; $i < $prices->count(); $i++) {
            $trend = $this->indicators->classifyTrend(
                $closes[$i],
                $sma20[$i],
                $sma50[$i],
                $sma200[$i],
                $rsi[$i],
                $macd['histogram'][$i],
            );

            $rows[] = [
                'stock_id' => $stock->id,
                'trade_date' => $prices[$i]->trade_date->toDateString(),
                'rsi_14' => $this->clean($rsi[$i]),
                'macd' => $this->clean($macd['macd'][$i], 6),
                'macd_signal' => $this->clean($macd['signal'][$i], 6),
                'macd_histogram' => $this->clean($macd['histogram'][$i], 6),
                'sma_20' => $this->clean($sma20[$i], 4),
                'sma_50' => $this->clean($sma50[$i], 4),
                'sma_200' => $this->clean($sma200[$i], 4),
                'ema_12' => $this->clean($ema12[$i], 4),
                'ema_26' => $this->clean($ema26[$i], 4),
                'bollinger_upper' => $this->clean($bands['upper'][$i], 4),
                'bollinger_middle' => $this->clean($bands['middle'][$i], 4),
                'bollinger_lower' => $this->clean($bands['lower'][$i], 4),
                'atr_14' => $this->clean($atr[$i], 4),
                'volume_sma_20' => $this->clean($volumeSma[$i], 4),
                'trend' => $trend['trend'],
                'trend_strength' => $trend['strength'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        // Chunked so a stock with years of history does not build one enormous
        // statement that trips max_allowed_packet.
        foreach (array_chunk($rows, 200) as $chunk) {
            TechnicalIndicator::upsert(
                $chunk,
                ['stock_id', 'trade_date'],
                [
                    'rsi_14', 'macd', 'macd_signal', 'macd_histogram',
                    'sma_20', 'sma_50', 'sma_200', 'ema_12', 'ema_26',
                    'bollinger_upper', 'bollinger_middle', 'bollinger_lower',
                    'atr_14', 'volume_sma_20', 'trend', 'trend_strength', 'updated_at',
                ],
            );
        }

        return count($rows);
    }

    /**
     * Guard against NaN/INF reaching a decimal column, which MySQL rejects.
     */
    private function clean(?float $value, int $precision = 4): ?float
    {
        if ($value === null || is_nan($value) || is_infinite($value)) {
            return null;
        }

        return round($value, $precision);
    }
}
