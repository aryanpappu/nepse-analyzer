<?php

declare(strict_types=1);

namespace App\Services\Scraper\Sources;

use App\Exceptions\ScrapeException;
use App\Services\Scraper\Contracts\PriceSource;
use App\Services\Scraper\FundamentalRow;
use App\Services\Scraper\HttpFetcher;
use App\Services\Scraper\IndexSnapshot;
use App\Support\Numeric;
use Illuminate\Support\Carbon;
use Symfony\Component\DomCrawler\Crawler;

abstract class AbstractHtmlSource implements PriceSource
{
    public function __construct(
        protected readonly HttpFetcher $http,
    ) {}

    /** @return array<string, mixed> */
    protected function config(): array
    {
        return (array) config('nepse.source_config.'.$this->key(), []);
    }

    public function supportsFundamentals(): bool
    {
        return false;
    }

    public function fetchFundamentals(string $symbol, ?string $sectorHint = null): ?FundamentalRow
    {
        return null;
    }

    /**
     * No index summary by default — most sources only publish prices.
     * {@see SharesansarSource} overrides this.
     */
    public function fetchIndices(?Carbon $date = null): IndexSnapshot
    {
        return new IndexSnapshot(tradeDate: null, rows: []);
    }

    // ------------------------------------------------------------------
    // Shared HTML helpers
    // ------------------------------------------------------------------

    /**
     * Build a map of normalised header label => column index.
     *
     * Matching on the header text rather than a hardcoded position is what lets
     * these parsers survive a site adding or reordering a column, which happens
     * far more often than a column being renamed.
     *
     * @return array<string, int>
     */
    protected function headerMap(Crawler $table): array
    {
        $map = [];

        $headerCells = $table->filter('thead th');

        if ($headerCells->count() === 0) {
            $headerCells = $table->filter('tr')->first()->filter('th');
        }

        $headerCells->each(function (Crawler $cell, int $index) use (&$map): void {
            $label = $this->normalizeHeader($cell->text(''));

            // First occurrence wins: some tables repeat a label (e.g. "Signal")
            // and the earlier column is the one that aligns with the data.
            if ($label !== '' && ! isset($map[$label])) {
                $map[$label] = $index;
            }
        });

        return $map;
    }

    protected function normalizeHeader(string $label): string
    {
        $clean = strtolower(Numeric::text($label));

        // The percent sign is meaning, not decoration: these tables carry both a
        // "Diff" column in rupees and a "Diff %" column, and stripping the sign
        // would collapse them to the same key — which silently maps a rupee
        // change into a percentage field.
        $clean = str_replace('%', ' percent ', $clean);

        // Drop everything else that varies cosmetically between renders.
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $clean) ?? '');
    }

    /**
     * Resolve the first header alias that exists in the map.
     *
     * @param  array<string, int>  $map
     * @param  list<string>  $aliases
     */
    protected function columnIndex(array $map, array $aliases): ?int
    {
        foreach ($aliases as $alias) {
            $needle = $this->normalizeHeader($alias);

            if (isset($map[$needle])) {
                return $map[$needle];
            }
        }

        // Fall back to a prefix match — "vol" should find "vol 000".
        foreach ($aliases as $alias) {
            $needle = $this->normalizeHeader($alias);

            foreach ($map as $label => $index) {
                if ($needle !== '' && str_starts_with($label, $needle)) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $cells
     */
    protected function cell(array $cells, ?int $index): ?string
    {
        if ($index === null || ! array_key_exists($index, $cells)) {
            return null;
        }

        return $cells[$index];
    }

    /**
     * @param  list<string>  $cells
     */
    protected function numberAt(array $cells, ?int $index): ?float
    {
        return Numeric::parse($this->cell($cells, $index));
    }

    /**
     * Extract every row of a table as a list of trimmed cell strings.
     *
     * @return list<list<string>>
     */
    protected function rows(Crawler $table): array
    {
        $body = $table->filter('tbody tr');

        if ($body->count() === 0) {
            $body = $table->filter('tr');
        }

        return $body->each(static function (Crawler $row): array {
            return $row->filter('td')->each(
                static fn (Crawler $cell): string => Numeric::text($cell->text(''))
            );
        });
    }

    /**
     * Locate a table, throwing a descriptive error when the selector misses so
     * the failure says "markup changed" rather than surfacing as an empty run.
     *
     * @throws ScrapeException
     */
    protected function requireTable(Crawler $page, string $selector, string $description): Crawler
    {
        $table = $page->filter($selector);

        if ($table->count() === 0) {
            throw ScrapeException::markupChanged(
                $this->key(),
                "could not find {$description} using selector '{$selector}'",
            );
        }

        return $table->first();
    }

    /**
     * Normalise a scraped ticker. Sources pad symbols with promoter/ordinary
     * suffixes and stray whitespace.
     */
    protected function normalizeSymbol(?string $symbol): ?string
    {
        $clean = strtoupper(Numeric::text($symbol));
        $clean = preg_replace('/[^A-Z0-9\-]/', '', $clean) ?? '';

        return $clean === '' ? null : $clean;
    }
}
