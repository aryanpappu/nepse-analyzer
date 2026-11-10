<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Services\Scraper\Contracts\FundamentalsSource;
use App\Services\Scraper\Contracts\PriceSource;
use App\Services\Scraper\Sources\MerolaganiSource;
use App\Services\Scraper\Sources\SharesansarQuarterlySource;
use App\Services\Scraper\Sources\SharesansarSource;
use InvalidArgumentException;

/**
 * Registry of crawlable sources. Keeping construction here means adding a new
 * site is one class plus one line, and the rest of the pipeline is unchanged.
 */
final class PriceSourceManager
{
    /** @var array<string, class-string<PriceSource>> */
    private const DRIVERS = [
        'sharesansar' => SharesansarSource::class,
        'merolagani' => MerolaganiSource::class,
    ];

    /**
     * Sources that serve fundamentals but publish no price table, so they are
     * not part of the price failover chain.
     *
     * @var array<string, class-string<FundamentalsSource>>
     */
    private const FUNDAMENTALS_DRIVERS = [
        'sharesansar_quarterly' => SharesansarQuarterlySource::class,
    ];

    /** @var array<string, PriceSource> */
    private array $resolved = [];

    /** @var array<string, FundamentalsSource> */
    private array $resolvedFundamentals = [];

    public function __construct(
        private readonly HttpFetcher $http,
        private readonly QuarterlyReportParser $quarterlyParser,
    ) {}

    public function make(string $key): PriceSource
    {
        $key = strtolower(trim($key));

        if (! isset(self::DRIVERS[$key])) {
            throw new InvalidArgumentException(
                "Unknown NEPSE price source [{$key}]. Available: ".implode(', ', array_keys(self::DRIVERS))
            );
        }

        return $this->resolved[$key] ??= new (self::DRIVERS[$key])($this->http);
    }

    /**
     * Sources in the configured failover order.
     *
     * @return list<PriceSource>
     */
    public function enabled(): array
    {
        $keys = (array) config('nepse.sources', []);
        $sources = [];

        foreach ($keys as $key) {
            if (isset(self::DRIVERS[strtolower(trim($key))])) {
                $sources[] = $this->make($key);
            }
        }

        // Never leave the pipeline with nothing to try.
        return $sources === [] ? [$this->make('sharesansar')] : $sources;
    }

    /**
     * Sources that can crawl per-company fundamentals, in configured order.
     *
     * Order is significant: FundamentalRow::mergeWith lets the earlier source
     * win on conflict, so the list is ordered richest-first. The quarterly
     * report leads because it carries the issuer's own filed figures, where the
     * market pages carry a derived subset.
     *
     * @return list<FundamentalsSource>
     */
    public function fundamentalSources(): array
    {
        $sources = [];

        foreach ((array) config('nepse.fundamental_sources', []) as $key) {
            $key = strtolower(trim((string) $key));

            if (isset(self::FUNDAMENTALS_DRIVERS[$key])) {
                $sources[] = $this->makeFundamentals($key);

                continue;
            }

            if (isset(self::DRIVERS[$key]) && $this->make($key)->supportsFundamentals()) {
                $sources[] = $this->make($key);
            }
        }

        if ($sources !== []) {
            return $sources;
        }

        // Unconfigured: fall back to whichever price sources are enabled, which
        // is what this method meant before fundamentals had their own list.
        return array_values(array_filter(
            $this->enabled(),
            static fn (PriceSource $source): bool => $source->supportsFundamentals(),
        ));
    }

    private function makeFundamentals(string $key): FundamentalsSource
    {
        return $this->resolvedFundamentals[$key] ??= match (self::FUNDAMENTALS_DRIVERS[$key]) {
            SharesansarQuarterlySource::class => new SharesansarQuarterlySource($this->http, $this->quarterlyParser),
        };
    }

    /** @return list<string> */
    public function availableKeys(): array
    {
        return array_keys(self::DRIVERS);
    }

    /** @return list<string> */
    public function availableFundamentalKeys(): array
    {
        return array_merge(array_keys(self::DRIVERS), array_keys(self::FUNDAMENTALS_DRIVERS));
    }
}
