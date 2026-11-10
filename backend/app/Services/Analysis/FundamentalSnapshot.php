<?php

declare(strict_types=1);

namespace App\Services\Analysis;

use App\Models\Stock;
use App\Models\StockPrice;

/**
 * Every comparable metric for one scrip, resolved to plain numbers.
 *
 * The point of this class is that there is exactly one implementation of "what
 * is this scrip's DEPS yield" and "how far is it below its 52-week high". The
 * head-to-head table, the sector percentiles and the AI prompt all read the
 * same values from here, so a screen and a prompt can never disagree — which is
 * the failure mode that makes an analysis tool untrustworthy.
 *
 * Values are nullable throughout. A missing metric is reported as missing; it is
 * never defaulted to zero, which would read as "this bank has no bad loans"
 * rather than "we do not know".
 */
final readonly class FundamentalSnapshot
{
    /**
     * @param  array<string, float|null>  $metrics  Keyed by MetricCatalog key.
     */
    private function __construct(
        public string $symbol,
        public string $name,
        public ?string $sector,
        public ?string $fiscalPeriod,
        public array $metrics,
    ) {}

    /**
     * Build from metrics that came from somewhere other than the database — a
     * what-if screen, a comparison against a figure the user typed in, or a
     * test. Unknown keys are ignored and absent ones stay null.
     *
     * @param  array<string, float|null>  $metrics  Keyed by MetricCatalog key.
     */
    public static function of(
        string $symbol,
        array $metrics,
        ?string $name = null,
        ?string $sector = null,
        ?string $fiscalPeriod = null,
    ): self {
        $known = [];

        foreach (array_keys(MetricCatalog::keyed()) as $key) {
            $known[$key] = $metrics[$key] ?? null;
        }

        return new self(
            symbol: strtoupper($symbol),
            name: $name ?? strtoupper($symbol),
            sector: $sector,
            fiscalPeriod: $fiscalPeriod,
            metrics: $known,
        );
    }

    /**
     * Build from an already-loaded stock.
     *
     * Callers should eager-load `latestFundamental`, `latestPrice` and `sector`;
     * this does no querying of its own so it stays safe to call in a loop.
     */
    public static function for(Stock $stock): self
    {
        $fundamental = $stock->latestFundamental;
        $price = $stock->latestPrice;
        $lastPrice = self::float($stock->last_price) ?? self::float($price?->close);

        return new self(
            symbol: $stock->symbol,
            name: $stock->name ?? $stock->symbol,
            sector: $stock->sector?->name,
            fiscalPeriod: $fundamental?->fiscal_period,
            metrics: [
                'share_capital' => self::float($fundamental?->share_capital)
                    ?? self::float($fundamental?->paid_up_capital),
                'distributable_eps' => self::float($fundamental?->distributable_eps),
                // Recomputed against today's price rather than read back from
                // the column. Both yields are price-derived but the crawl that
                // wrote them runs weekly, so the stored figure is up to seven
                // sessions stale — and a yield quoted against last week's price
                // is exactly the kind of quietly-wrong number this whole change
                // exists to eliminate. The column is kept as the value at
                // filing time; the live figure is what gets compared.
                'distributable_eps_yield' => self::ratioPercent(
                    self::float($fundamental?->distributable_eps),
                    $lastPrice,
                ) ?? self::float($fundamental?->distributable_eps_yield),
                'eps' => self::float($fundamental?->eps),
                'pe_ratio' => self::float($fundamental?->pe_ratio),
                'book_value' => self::float($fundamental?->book_value),
                'pb_ratio' => self::float($fundamental?->pb_ratio),
                'roe' => self::float($fundamental?->roe),
                'roa' => self::float($fundamental?->roa),
                'dividend_yield' => self::cashDividendYield($fundamental, $stock, $lastPrice)
                    ?? self::float($fundamental?->dividend_yield),
                'upside_to_52w_high_percent' => self::upside($price, $lastPrice),
                'premium_over_52w_low_percent' => self::premium($price, $lastPrice),
                'npl_percent' => self::float($fundamental?->npl_percent),
                'capital_adequacy_ratio' => self::float($fundamental?->capital_adequacy_ratio),
                'base_rate' => self::float($fundamental?->base_rate),
                'last_price' => $lastPrice,
                'market_capitalization' => self::float($stock->market_capitalization),
            ],
        );
    }

    public function get(string $key): ?float
    {
        return $this->metrics[$key] ?? null;
    }

    /**
     * How much is actually known, as a share of the scored metric set.
     *
     * Surfaced rather than hidden because a head-to-head where one side has
     * three metrics and the other has twelve is not a fair fight, and the
     * reader should be told before the verdict is.
     */
    public function coverage(): float
    {
        $scored = MetricCatalog::scored();
        $known = 0;

        foreach ($scored as $metric) {
            if ($this->get($metric->key) !== null) {
                $known++;
            }
        }

        return $scored === [] ? 0.0 : round($known / count($scored) * 100, 1);
    }

    /**
     * Distance back to the 52-week high, as a percentage of the current price.
     *
     * Published tables quote the rupee gap, which cannot be compared between
     * scrips at different price levels — an NPR 8 gap on a NPR 552 share is
     * twice the move an NPR 5 gap is on a NPR 725 one.
     */
    private static function upside(?StockPrice $price, ?float $lastPrice): ?float
    {
        $high = self::float($price?->fifty_two_week_high);

        if ($high === null || $lastPrice === null || $lastPrice <= 0) {
            return null;
        }

        return round(($high - $lastPrice) / $lastPrice * 100, 4);
    }

    private static function premium(?StockPrice $price, ?float $lastPrice): ?float
    {
        $low = self::float($price?->fifty_two_week_low);

        if ($low === null || $lastPrice === null || $low <= 0) {
            return null;
        }

        return round(($lastPrice - $low) / $low * 100, 4);
    }

    /**
     * Cash dividend yield against today's price.
     *
     * NEPSE quotes dividends as a percentage of par value, so a 12.5% cash
     * dividend on a NPR 100 par share is NPR 12.50 — the same derivation the
     * crawler does, repeated here against the current price rather than the
     * price on the day of the weekly crawl. Bonus shares are excluded: they
     * dilute rather than pay.
     */
    private static function cashDividendYield(mixed $fundamental, Stock $stock, ?float $lastPrice): ?float
    {
        $cash = self::float($fundamental?->cash_dividend_percent);

        if ($cash === null || $lastPrice === null || $lastPrice <= 0) {
            return null;
        }

        $par = self::float($stock->paid_up_value);
        $par = $par !== null && $par > 0 ? $par : 100.0;

        return round($cash / 100 * $par / $lastPrice * 100, 4);
    }

    private static function ratioPercent(?float $numerator, ?float $denominator): ?float
    {
        if ($numerator === null || $denominator === null || $denominator <= 0) {
            return null;
        }

        return round($numerator / $denominator * 100, 4);
    }

    /**
     * Eloquent hands back decimal casts as strings; normalise once here rather
     * than sprinkling (float) casts through every consumer.
     */
    private static function float(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    /**
     * Relations `for()` reads. Eager-load these before calling it in a loop.
     *
     * @return list<string>
     */
    public static function relationsToLoad(): array
    {
        return ['sector', 'latestFundamental', 'latestPrice'];
    }
}
