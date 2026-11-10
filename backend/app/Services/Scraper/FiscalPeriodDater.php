<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Support\NepaliFiscalYear;
use Illuminate\Support\Carbon;

/**
 * Turns a scraped `fiscal_period` string into the Gregorian date the figures
 * pertain to.
 *
 * `fiscal_period` is not one format, because it is not one source. Three
 * crawlers write it and each publishes the period differently:
 *
 * | Producer                       | Shape          | Meaning                    |
 * |--------------------------------|----------------|----------------------------|
 * | {@see QuarterlyReportParser}   | `2082/2083 Q3` | the filed reporting quarter |
 * | {@see Sources\MerolaganiSource}| `082-083 Q3`   | same, from `(FY:082-083, Q:3)` |
 * | {@see Sources\SharesansarSource}| `2081/2082`   | the fiscal year a payout is for |
 *
 * plus `unknown` when a page carried no period at all. Two- and three-digit BS
 * years, `/` and `-` separators, quarter present or absent. All of it is
 * accepted here so no downstream consumer has to know which crawler wrote a
 * row.
 *
 * Pure and database-free by design: the conversion is the part worth pinning
 * down in tests, and it must not need a connection to do so.
 *
 * Three things are deliberately *not* done:
 *
 * - **No calendar is invented.** Everything Gregorian comes from
 *   {@see NepaliFiscalYear}, which concedes that BS boundaries need a lookup
 *   table because no formula gives them.
 * - **A date that cannot be known is not guessed.** An unparseable or
 *   not-yet-ended period returns undated, with its reason, and the caller
 *   leaves `reported_on` NULL.
 * - **The clock is not the arbiter of "has this period ended".** A future
 *   reporting date would be worse than none — it sorts ahead of every real
 *   filing — so periods are checked against a date the caller *observed*
 *   (the page's own session, or the newest stored trade date), never against
 *   `now()`. Omit it and no such check is made, rather than a hidden one
 *   against local time.
 */
final class FiscalPeriodDater
{
    /**
     * The literal the crawlers write when a page published no period. Not a
     * parse failure — a recorded absence, and worth distinguishing in the
     * rejection counts from a string we simply could not read.
     */
    public const UNKNOWN = 'unknown';

    /**
     * BS years are written three ways in this data: `2082`, `082` (leading
     * century digit dropped) and `83` as the second half of `2082/83`. The
     * Nepali market has existed entirely within BS 20xx, which is also the
     * only century a dropped leading digit can unambiguously restore, so a
     * year pair outside it is a misparse rather than an old filing.
     */
    private const BS_CENTURY_FLOOR = 2000;

    private const BS_CENTURY_CEILING = 2099;

    /**
     * @param  ?Carbon  $observedThrough  The latest date the caller has actually
     *                                    seen data for. A period ending after it
     *                                    has not been filed yet.
     */
    public function date(?string $fiscalPeriod, ?Carbon $observedThrough = null): FiscalPeriodDating
    {
        $raw = trim((string) $fiscalPeriod);

        if ($raw === '') {
            return FiscalPeriodDating::undatable($raw, 'fiscal period is empty');
        }

        if (strcasecmp($raw, self::UNKNOWN) === 0) {
            return FiscalPeriodDating::undatable($raw, 'the source published no reporting period');
        }

        $years = $this->yearPair($raw);

        if ($years === null) {
            return FiscalPeriodDating::undatable(
                $raw,
                'no fiscal-year pair could be read from the period',
            );
        }

        [$first, $second] = $years;

        // A fiscal year spans two consecutive Gregorian-overlapping BS years by
        // definition. A pair that does not increment is a mismapped column or a
        // half-parsed string, not a period.
        if ($second !== $first + 1) {
            return FiscalPeriodDating::undatable(
                $raw,
                sprintf('%d/%d is not a consecutive fiscal-year pair', $first, $second),
            );
        }

        if ($first < self::BS_CENTURY_FLOOR || $first > self::BS_CENTURY_CEILING) {
            return FiscalPeriodDating::undatable(
                $raw,
                sprintf('BS year %d falls outside the 20xx century every NEPSE filing sits in', $first),
            );
        }

        $quarter = $this->quarter($raw);
        $fiscalYear = NepaliFiscalYear::for($first);

        if ($quarter === null) {
            $endsOn = $fiscalYear->end;
            $exact = $fiscalYear->endIsExact();
            $label = sprintf('%d/%d', $first, $second);
        } else {
            $endsOn = $fiscalYear->quarterEnd($quarter);
            $exact = $fiscalYear->quarterEndIsExact($quarter);
            $label = sprintf('%d/%d Q%d', $first, $second, $quarter);
        }

        if ($observedThrough !== null && $endsOn->greaterThan($observedThrough->copy()->startOfDay())) {
            return FiscalPeriodDating::undatable(
                $raw,
                sprintf(
                    '%s ends %s, after the last observed session %s — the period has not been filed yet',
                    $label,
                    $endsOn->toDateString(),
                    $observedThrough->toDateString(),
                ),
            );
        }

        return FiscalPeriodDating::dated(
            raw: $raw,
            bsYear: $first,
            quarter: $quarter,
            endsOn: $endsOn,
            approximate: ! $exact,
            canonicalLabel: $label,
        );
    }

    /**
     * The quarter a period names, or null when it names none (an annual row).
     *
     * Both spellings the crawlers can produce are accepted: the canonical
     * `Q3` / `Q:3`, and the `3rd Quarter` wording of the filing header itself,
     * in case an uncanonicalised string ever reaches here.
     */
    private function quarter(string $raw): ?int
    {
        if (preg_match('/\bQ\s*:?\s*([1-4])\b/i', $raw, $match) === 1) {
            return (int) $match[1];
        }

        if (preg_match('/\b([1-4])\s*(?:st|nd|rd|th)?\s*quarter\b/i', $raw, $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }

    /**
     * The two BS years of the pair, normalised to four digits.
     *
     * @return array{int, int}|null
     */
    private function yearPair(string $raw): ?array
    {
        if (preg_match('/(\d{3,4})\s*[\/\-]\s*(\d{2,4})/', $raw, $match) !== 1) {
            return null;
        }

        $first = $this->fullYear($match[1]);

        if ($first === null) {
            return null;
        }

        $second = $this->fullYear($match[2], $first);

        if ($second === null) {
            return null;
        }

        return [$first, $second];
    }

    /**
     * `2082` => 2082, `082` => 2082, and `83` => 2083 given a 2082 reference.
     *
     * The three-digit form is ShareSansar's and Merolagani's habit of dropping
     * the leading `2`; the two-digit form only ever appears as the second half
     * of a pair, where the first year supplies the century.
     */
    private function fullYear(string $digits, ?int $reference = null): ?int
    {
        $value = (int) $digits;

        return match (strlen($digits)) {
            4 => $value,
            3 => str_starts_with($digits, '0') ? self::BS_CENTURY_FLOOR + $value : null,
            2 => $reference === null ? null : $this->completeCentury($value, $reference),
            default => null,
        };
    }

    /**
     * `83` against 2082 => 2083; `00` against 2099 => 2100.
     */
    private function completeCentury(int $twoDigits, int $reference): int
    {
        $candidate = $reference - ($reference % 100) + $twoDigits;

        return $candidate < $reference ? $candidate + 100 : $candidate;
    }
}
