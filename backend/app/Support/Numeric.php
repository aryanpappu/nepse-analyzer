<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Parsing helpers for values pulled out of scraped HTML tables.
 *
 * Scraped cells are messy in predictable ways: thousands separators, currency
 * prefixes, percent suffixes, non-breaking spaces, and a placeholder ("-", "N/A",
 * "--") that means "no trade" rather than zero. Getting that last distinction
 * wrong would silently write zero closes into the price history, so every
 * parser here returns null for unparseable input and never coerces to 0.
 */
final class Numeric
{
    /** Placeholders that mean "no value", not "zero". */
    private const NULL_TOKENS = ['', '-', '--', 'n/a', 'na', 'null', 'nan', '.', 'x'];

    public static function parse(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        // Non-breaking spaces and zero-width chars are common in these tables.
        $clean = str_replace(["\xc2\xa0", "\xe2\x80\x8b", "\xef\xbb\xbf"], ' ', $value);
        $clean = trim(strtolower($clean));

        if (in_array($clean, self::NULL_TOKENS, true)) {
            return null;
        }

        // Parentheses are an accounting convention for negatives: (1,234) = -1234
        $negative = str_starts_with($clean, '(') && str_ends_with($clean, ')');

        // Drop thousands separators, then extract the first number.
        //
        // Matching the number rather than stripping non-digits is what makes
        // currency prefixes work: stripping would turn "Rs. 938.90" into
        // ".938.90", which then fails a strict numeric check and silently
        // becomes null — and these pages do write "Rs. 100.00".
        $clean = str_replace(',', '', $clean);

        if (! preg_match('/-?\d+(?:\.\d+)?/', $clean, $matches)) {
            return null;
        }

        $candidate = $matches[0];

        // Reject values with trailing numeric junk, e.g. "12.3.4" or "1-2",
        // rather than quietly truncating them to something plausible.
        $remainder = str_replace($candidate, '', $clean);

        if (preg_match('/\d/', $remainder)) {
            return null;
        }

        $number = (float) $candidate;

        return $negative ? -abs($number) : $number;
    }

    /**
     * Same as parse() but for whole-number columns (volume, transactions).
     */
    public static function parseInt(?string $value): ?int
    {
        $parsed = self::parse($value);

        return $parsed === null ? null : (int) round($parsed);
    }

    /**
     * Collapse whitespace and strip surrounding junk from a text cell.
     */
    public static function text(?string $value): string
    {
        $clean = str_replace(["\xc2\xa0", "\xe2\x80\x8b"], ' ', (string) $value);

        return trim(preg_replace('/\s+/u', ' ', $clean) ?? '');
    }

    /**
     * Round to the given precision, preserving null.
     */
    public static function round(?float $value, int $precision = 2): ?float
    {
        return $value === null ? null : round($value, $precision);
    }

    /**
     * Percentage change between two values, null-safe and divide-by-zero safe.
     */
    public static function percentChange(?float $from, ?float $to): ?float
    {
        if ($from === null || $to === null || abs($from) < 1e-9) {
            return null;
        }

        return round(($to - $from) / abs($from) * 100, 2);
    }
}
