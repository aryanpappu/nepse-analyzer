<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Support\NepaliFiscalYear;
use Illuminate\Support\Carbon;

/**
 * The outcome of trying to turn a scraped `fiscal_period` string into a real
 * date. {@see FiscalPeriodDater} produces it.
 *
 * Two states, and the undated one carries its reason rather than being an
 * unexplained null: a period the sources genuinely cannot date must be
 * reportable as such, and "184 rows said `unknown`" is a different fact from
 * "184 rows had a year pair that did not increment", even though both leave
 * `reported_on` NULL.
 */
final readonly class FiscalPeriodDating
{
    private function __construct(
        /** The string as scraped, kept verbatim for logs and rejection samples. */
        public string $raw,
        /** First BS year of the pair — 2082 for "2082/2083 Q3". */
        public ?int $bsYear,
        /** 1-4, or null for an annual period that names no quarter. */
        public ?int $quarter,
        /** Gregorian date the period ends on: the date the figures pertain to. */
        public ?Carbon $endsOn,
        /**
         * True when endsOn is interpolated rather than a published boundary.
         * See {@see NepaliFiscalYear::quarterEnd()} — interim BS
         * quarter ends are not derivable from the fiscal-year table alone.
         */
        public bool $approximate,
        /** Canonical "2082/2083 Q3", so the many scraped spellings collapse. */
        public ?string $canonicalLabel,
        /** Why this period could not be dated, when it could not be. */
        public ?string $rejection,
    ) {}

    public static function dated(
        string $raw,
        int $bsYear,
        ?int $quarter,
        Carbon $endsOn,
        bool $approximate,
        string $canonicalLabel,
    ): self {
        return new self(
            raw: $raw,
            bsYear: $bsYear,
            quarter: $quarter,
            endsOn: $endsOn->copy()->startOfDay(),
            approximate: $approximate,
            canonicalLabel: $canonicalLabel,
            rejection: null,
        );
    }

    /**
     * A date the source stated outright rather than one we converted.
     *
     * No BS year or quarter, because a page that publishes a filing date does
     * not have to publish a period alongside it — and none is needed once the
     * date itself is in hand. Exact by definition: it was read, not inferred.
     */
    public static function fromPage(string $raw, Carbon $endsOn): self
    {
        return new self(
            raw: $raw,
            bsYear: null,
            quarter: null,
            endsOn: $endsOn->copy()->startOfDay(),
            approximate: false,
            canonicalLabel: $raw === '' ? null : $raw,
            rejection: null,
        );
    }

    public static function undatable(string $raw, string $reason): self
    {
        return new self(
            raw: $raw,
            bsYear: null,
            quarter: null,
            endsOn: null,
            approximate: false,
            canonicalLabel: null,
            rejection: $reason,
        );
    }

    public function isDated(): bool
    {
        return $this->endsOn !== null;
    }

    /**
     * The value for `stock_fundamentals.reported_on_precision`, or null when
     * there is no date to qualify. Kept here so the one place that decides
     * "exact" also names it, and no caller invents a third spelling.
     */
    public function precision(): ?string
    {
        if (! $this->isDated()) {
            return null;
        }

        return $this->approximate
            ? StockFundamentalPrecision::APPROXIMATE
            : StockFundamentalPrecision::EXACT;
    }

    public function reportedOn(): ?string
    {
        return $this->endsOn?->toDateString();
    }
}
