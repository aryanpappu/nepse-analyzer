<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use Illuminate\Support\Carbon;

/**
 * One scrip's price at one date, and where that price came from.
 *
 * The app has three places a mark can come from and they do not always agree:
 * `stock_prices.close` (the daily crawl), `fund_navs` (closed-end funds, which
 * never appear in the daily price table), and `stocks.last_price` — a spot
 * column overwritten by each crawl but also *seeded from a MeroShare CSV* on
 * import, which is how a scrip can carry a price with no row in either
 * time-series table. NICADF is exactly that: `last_price` 10.05, zero rows in
 * both series.
 *
 * A bare float cannot express the difference, and the difference is the whole
 * problem — a price with no series row behind it can mark today and cannot
 * mark a historical boundary. So a mark travels with its source, its as-of
 * date, and how stale it is, and `seriesBacked` says whether anything
 * reproducible stands behind it.
 *
 * `price` is null, never 0.0, when nothing marks the scrip.
 */
final class HoldingMark
{
    /** Daily crawl close from `stock_prices`. */
    public const SOURCE_CLOSE = 'stock_price_close';

    /** `fund_navs`, market price where published, else NAV. */
    public const SOURCE_NAV = 'fund_nav';

    /** `stocks.last_price`, dated by `stocks.last_traded_on`. */
    public const SOURCE_SPOT = 'stock_last_price';

    /** Nothing prices this scrip at this date. */
    public const SOURCE_NONE = 'none';

    public function __construct(
        public readonly int $stockId,
        public readonly ?float $price,
        public readonly string $source,
        /** The date the price is *as of*, which is not the date it was asked for. */
        public readonly ?Carbon $asOf,
        /** Calendar days between asOf and the requested date; null when unpriced. */
        public readonly ?int $staleDays,
        /** Whether a dated row in stock_prices or fund_navs stands behind this price. */
        public readonly bool $seriesBacked,
        /** Whether staleDays exceeds the reporting threshold. Never suppresses the price. */
        public readonly bool $isStale,
        public readonly string $reason,
    ) {}

    public function isPriced(): bool
    {
        return $this->price !== null;
    }

    public function valueOf(float $quantity): ?float
    {
        return $this->price === null ? null : round($quantity * $this->price, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'stock_id' => $this->stockId,
            'price' => $this->price,
            'source' => $this->source,
            'as_of' => $this->asOf?->toDateString(),
            'stale_days' => $this->staleDays,
            'series_backed' => $this->seriesBacked,
            'is_stale' => $this->isStale,
            'reason' => $this->reason,
        ];
    }
}
