<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Support\Numeric;

/**
 * The fixed vocabulary of NEPSE/ShareSansar indices: the headline NEPSE and
 * Sensitive indices plus the sector sub-indices.
 *
 * This is the single source of truth for index *names*, shared by every place
 * that needs to agree on one: the live "today's value" widget, the historical
 * datafeed, and — because {@see IndexRow::slug()} derives the persisted
 * `market_indices.slug` from the name — the upsert key that ties a daily
 * reading to its own history. A name here must read identically wherever it
 * is produced, or the same index would silently split into two rows.
 *
 * ShareSansar truncates long labels in its live widget (e.g. "Development
 * Bank Ind." for "Development Bank Index") but not in its historical index
 * picker, so {@see canonicalize()} recovers the untruncated name by prefix
 * match rather than trusting the widget's own spelling.
 */
final class IndexCatalog
{
    /**
     * Canonical, untruncated index names as ShareSansar's own index-history
     * picker spells them.
     *
     * @var list<string>
     */
    public const CANONICAL_NAMES = [
        'Banking SubIndex',
        'Development Bank Index',
        'Finance Index',
        'Float Index',
        'Hotels And Tourism',
        'HydroPower Index',
        'Insurance',
        'Investment',
        'Life Insurance',
        'Manufacturing And Processing',
        'Microfinance Index',
        'Mutual Fund',
        'NEPSE Index',
        'Non Life Insurance',
        'Others Index',
        'Sensitive Float Index',
        'Sensitive Index',
        'Trading Index',
    ];

    /**
     * Resolve a label scraped from any ShareSansar page to its canonical form.
     *
     * Tries an exact case-insensitive match first (cheap, and correct for the
     * 14 of 17 live-widget labels that are not truncated), then falls back to
     * prefix matching against the trailing "." ShareSansar's widget leaves
     * behind when it truncates a name — e.g. "Sensitive Float Inde." matches
     * "Sensitive Float Index".
     *
     * Returns null rather than guessing when nothing matches, so an index the
     * site adds later is skipped and logged instead of silently mis-slugged.
     */
    public static function canonicalize(string $rawLabel): ?string
    {
        $clean = trim(Numeric::text($rawLabel));

        if ($clean === '') {
            return null;
        }

        $needle = strtolower($clean);

        foreach (self::CANONICAL_NAMES as $name) {
            if (strtolower($name) === $needle) {
                return $name;
            }
        }

        // Strip the truncation marker and retry as a prefix match.
        $stripped = strtolower(rtrim($clean, ". \t"));

        if ($stripped === '') {
            return null;
        }

        foreach (self::CANONICAL_NAMES as $name) {
            if (str_starts_with(strtolower($name), $stripped)) {
                return $name;
            }
        }

        return null;
    }
}
