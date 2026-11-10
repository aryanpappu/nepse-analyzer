<?php

declare(strict_types=1);

namespace App\Services\Scraper\Sources;

use App\Exceptions\ScrapeException;
use App\Services\Scraper\HttpFetcher;
use App\Services\Scraper\IndexCatalog;
use App\Services\Scraper\IndexHistoryBar;
use App\Support\Numeric;
use Illuminate\Support\Carbon;

/**
 * Historical daily index bars from ShareSansar's `/index-history-data` feed.
 *
 * This is the only source found that publishes multi-year index history: the
 * per-symbol chart datafeed {@see SharesansarChartSource} talks to
 * (`/company-chart/history`) answers "no_data" for every index identifier
 * tried against it, so it does not double as an index feed. `/index-history-data`
 * is a different endpoint entirely — a server-side DataTables listing behind
 * the "Index History Data" page — and it goes back much further: 1997-07-20
 * for NEPSE Index itself (6,694 daily rows), and to roughly 2011 for most
 * sector sub-indices, which is when ShareSansar appears to have started
 * publishing them individually.
 *
 * It requires no session, cookie or CSRF token — a plain GET answers JSON —
 * but it caps `length` at 50 rows per page server side (verified empirically:
 * higher values silently return zero rows rather than an error), so reaching
 * back years means paginating with `start`.
 */
final class SharesansarIndexHistorySource
{
    private const PATH_CONFIG_KEY = 'index_history_url';

    private const DEFAULT_PATH = 'https://www.sharesansar.com/index-history-data';

    /** Rows per page. Confirmed the server's own ceiling; higher silently yields none. */
    private const PAGE_SIZE = 50;

    /** Bails out of a runaway pagination loop rather than looping forever on a feed bug. */
    private const MAX_PAGES = 400;

    /**
     * Canonical index name => ShareSansar's own internal index_id.
     *
     * These ids are the site's private implementation detail (read off the
     * "Select Indices" dropdown on /index-history-data), not a general NEPSE
     * concept, which is why they live here rather than in IndexCatalog. The
     * *names* must still match IndexCatalog::CANONICAL_NAMES exactly, since
     * that is what keeps a historical row's slug identical to the same
     * index's daily reading.
     *
     * @var array<string, int>
     */
    private const INDEX_IDS = [
        'Banking SubIndex' => 1,
        'Development Bank Index' => 2,
        'Finance Index' => 3,
        'Float Index' => 4,
        'Hotels And Tourism' => 5,
        'HydroPower Index' => 6,
        'Insurance' => 7,
        'Life Insurance' => 8,
        'Manufacturing And Processing' => 9,
        'Microfinance Index' => 10,
        'Mutual Fund' => 11,
        'NEPSE Index' => 12,
        'Non Life Insurance' => 13,
        'Others Index' => 14,
        'Sensitive Float Index' => 15,
        'Sensitive Index' => 16,
        'Trading Index' => 17,
        'Investment' => 18,
    ];

    public function __construct(
        private readonly HttpFetcher $http,
    ) {}

    public function key(): string
    {
        return 'sharesansar_index_history';
    }

    /**
     * Indices this source can backfill, in IndexCatalog's canonical order.
     *
     * @return list<string>
     */
    public function availableIndices(): array
    {
        return array_values(array_filter(
            IndexCatalog::CANONICAL_NAMES,
            static fn (string $name): bool => isset(self::INDEX_IDS[$name]),
        ));
    }

    /**
     * Daily bars for one index, oldest first.
     *
     * @return list<IndexHistoryBar>
     *
     * @throws ScrapeException
     */
    public function fetchIndexHistory(string $indexName, Carbon $from, Carbon $to): array
    {
        $indexId = self::INDEX_IDS[$indexName] ?? null;

        if ($indexId === null) {
            throw new ScrapeException(
                "[{$this->key()}] unknown index [{$indexName}]. Available: ".implode(', ', $this->availableIndices()),
                ['index' => $indexName],
            );
        }

        $url = (string) (config('nepse.source_config.sharesansar.'.self::PATH_CONFIG_KEY) ?: self::DEFAULT_PATH);
        $bars = [];
        $start = 0;
        $page = 0;

        do {
            $body = $this->http->get(
                $this->key(),
                $url,
                [
                    'index_id' => $indexId,
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'draw' => 1,
                    'start' => $start,
                    'length' => self::PAGE_SIZE,
                ],
                [
                    'Referer' => $url,
                    'X-Requested-With' => 'XMLHttpRequest',
                ],
            );

            $payload = json_decode($body, true);

            if (! is_array($payload) || ! isset($payload['data']) || ! is_array($payload['data'])) {
                throw ScrapeException::markupChanged(
                    $this->key(),
                    "index-history feed for [{$indexName}] did not return the expected JSON shape",
                );
            }

            $rowsOnPage = $payload['data'];
            $recordsTotal = (int) ($payload['recordsFiltered'] ?? $payload['recordsTotal'] ?? 0);

            foreach ($rowsOnPage as $raw) {
                $bar = $this->parseRow($indexName, $raw);

                if ($bar !== null) {
                    $bars[] = $bar;
                }
            }

            $start += self::PAGE_SIZE;
            $page++;
        } while (count($rowsOnPage) === self::PAGE_SIZE && $start < $recordsTotal && $page < self::MAX_PAGES);

        // Guarantee oldest-first regardless of how the feed ordered a page,
        // matching SharesansarChartSource's own contract.
        usort($bars, static fn (IndexHistoryBar $a, IndexHistoryBar $b): int => $a->date <=> $b->date);

        return $bars;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function parseRow(string $indexName, array $raw): ?IndexHistoryBar
    {
        $value = Numeric::parse(isset($raw['current']) ? (string) $raw['current'] : null);
        $publishedDate = isset($raw['published_date']) ? (string) $raw['published_date'] : null;

        if ($value === null || $value <= 0 || $publishedDate === null) {
            return null;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}/', $publishedDate)) {
            return null;
        }

        return new IndexHistoryBar(
            name: $indexName,
            date: Carbon::parse($publishedDate)->startOfDay(),
            value: $value,
            open: Numeric::parse(isset($raw['open']) ? (string) $raw['open'] : null),
            high: Numeric::parse(isset($raw['high']) ? (string) $raw['high'] : null),
            low: Numeric::parse(isset($raw['low']) ? (string) $raw['low'] : null),
            changeAmount: Numeric::parse(isset($raw['change_']) ? (string) $raw['change_'] : null),
            changePercent: Numeric::parse(isset($raw['per_change']) ? (string) $raw['per_change'] : null),
            totalTurnover: Numeric::parse(isset($raw['turnover']) ? (string) $raw['turnover'] : null),
        );
    }
}
