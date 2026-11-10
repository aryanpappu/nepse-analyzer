<?php

declare(strict_types=1);

namespace App\Services\Scraper;

/**
 * One scraped row of daily trading data, normalised across sources.
 *
 * Immutable by design: sources produce these, the validator inspects them, and
 * the persister writes them. Nothing mutates a row after it is parsed, so a
 * bad write cannot be blamed on a later stage.
 */
final readonly class PriceRow
{
    public function __construct(
        public string $symbol,
        public ?string $name,
        public ?string $sector,
        public float $close,
        public ?float $open = null,
        public ?float $high = null,
        public ?float $low = null,
        public ?float $previousClose = null,
        public ?float $averagePrice = null,
        public ?float $changePercent = null,
        public int $volume = 0,
        public float $turnover = 0.0,
        public ?int $transactions = null,
        public ?float $fiftyTwoWeekHigh = null,
        public ?float $fiftyTwoWeekLow = null,
        public ?float $marketCapitalization = null,
    ) {}

    /**
     * A copy with the intraday bar removed, keeping the close.
     *
     * Used when a source reports an internally inconsistent OHLC (some sites
     * publish a separately-determined close for thinly traded scrips that sits
     * outside their own high-low range). The close is still trustworthy — it is
     * corroborated by the last traded price — so the bar is dropped rather than
     * the whole row.
     */
    public function withoutIntradayBar(): self
    {
        return new self(
            symbol: $this->symbol,
            name: $this->name,
            sector: $this->sector,
            close: $this->close,
            open: null,
            high: null,
            low: null,
            previousClose: $this->previousClose,
            averagePrice: $this->averagePrice,
            changePercent: $this->changePercent,
            volume: $this->volume,
            turnover: $this->turnover,
            transactions: $this->transactions,
            fiftyTwoWeekHigh: $this->fiftyTwoWeekHigh,
            fiftyTwoWeekLow: $this->fiftyTwoWeekLow,
            marketCapitalization: $this->marketCapitalization,
        );
    }

    /**
     * Change in rupees, derived rather than stored so it can never disagree
     * with close/previousClose.
     */
    public function changeAmount(): ?float
    {
        if ($this->previousClose === null) {
            return null;
        }

        return round($this->close - $this->previousClose, 2);
    }

    /**
     * Percent change.
     *
     * Computed from close and previous close whenever both are known, and only
     * falling back to the source's own column otherwise. That ordering is
     * deliberate: the arithmetic is ours and cannot be wrong, whereas a scraped
     * column can silently be the wrong column. These tables publish a rupee
     * "Diff" next to a "Diff %", and trusting the column first meant a mapping
     * slip stored a rupee amount as a percentage.
     */
    public function resolvedChangePercent(): ?float
    {
        if ($this->previousClose !== null && abs($this->previousClose) > 1e-9) {
            return round(($this->close - $this->previousClose) / $this->previousClose * 100, 2);
        }

        return $this->changePercent;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'name' => $this->name,
            'sector' => $this->sector,
            'open' => $this->open,
            'high' => $this->high,
            'low' => $this->low,
            'close' => $this->close,
            'previous_close' => $this->previousClose,
            'average_price' => $this->averagePrice,
            'change_percent' => $this->resolvedChangePercent(),
            'volume' => $this->volume,
            'turnover' => $this->turnover,
            'transactions' => $this->transactions,
        ];
    }
}
