<?php

declare(strict_types=1);

namespace App\Services\Playbook;

use App\Models\Stock;
use App\Models\StockFundamental;
use App\Models\TechnicalIndicator;

/**
 * Everything the playbook engine is allowed to look at for one scrip.
 *
 * Bundling it up front keeps the engine a pure function of its inputs — no
 * lazy relation loads mid-scoring, so scoring 375 scrips is a fixed number of
 * queries rather than a few thousand.
 */
final class PlaybookInputs
{
    public function __construct(
        public readonly Stock $stock,
        public readonly float $price,
        public readonly ?TechnicalIndicator $indicator,
        public readonly ?StockFundamental $fundamental,
        /** Sessions of stored history — gates whether a profile can judge at all. */
        public readonly int $sessions,
        public readonly ?float $high20,
        public readonly ?float $low20,
        public readonly ?float $high52w,
        public readonly ?float $low52w,
        public readonly ?float $volume,
    ) {}

    public function atr(): ?float
    {
        $atr = $this->indicator?->atr_14;

        return $atr === null ? null : (float) $atr;
    }

    /**
     * Where today's close sits inside the 20-session range, 0-1.
     *
     * The cleanest single momentum read available from daily bars: near 1 the
     * scrip is breaking out, near 0 it is being sold into the close.
     */
    public function rangePosition20(): ?float
    {
        if ($this->high20 === null || $this->low20 === null) {
            return null;
        }

        $span = $this->high20 - $this->low20;

        if ($span <= 0.0) {
            return null;
        }

        return max(0.0, min(1.0, ($this->price - $this->low20) / $span));
    }

    /**
     * How far below the 52-week high, as a positive fraction.
     */
    public function drawdownFrom52wHigh(): ?float
    {
        if ($this->high52w === null || $this->high52w <= 0.0) {
            return null;
        }

        return max(0.0, ($this->high52w - $this->price) / $this->high52w);
    }

    /**
     * Today's volume against the 20-session average. 1.0 means average.
     */
    public function volumeRatio(): ?float
    {
        $average = $this->indicator?->volume_sma_20;

        if ($this->volume === null || $average === null || (float) $average <= 0.0) {
            return null;
        }

        return $this->volume / (float) $average;
    }

    public function isFund(): bool
    {
        return $this->stock->instrument_type === 'mutual_fund';
    }
}
