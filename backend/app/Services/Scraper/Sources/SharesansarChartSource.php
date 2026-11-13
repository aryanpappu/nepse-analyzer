<?php

declare(strict_types=1);

namespace App\Services\Scraper\Sources;

use App\Exceptions\ScrapeException;
use App\Services\Scraper\Contracts\HistorySource;
use App\Services\Scraper\HistoricalBar;
use App\Services\Scraper\HttpFetcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Historical daily bars from the chart datafeed behind the company page.
 *
 * The company pages render a TradingView chart backed by a UDF-compatible
 * endpoint, which serves plain JSON OHLCV. That makes it the only practical way
 * to backfill history: the daily price tables publish one session at a time and
 * ignore any date filter, so without this a fresh install would need months of
 * daily runs before a 90-day chart or a 200-day moving average existed.
 *
 * The bars were verified against the daily price table for the same session and
 * matched exactly on open, high, low, close and volume.
 *
 * The API caps each response to roughly 350 trading bars regardless of the
 * requested countback. When more than MAX_BARS_PER_REQUEST bars are needed the
 * source makes multiple windowed requests, sliding the `to` timestamp backwards
 * to the day before the earliest bar returned by each window, until the full
 * `$days` target is reached or the source has nothing older.
 */
final class SharesansarChartSource implements HistorySource
{
    private const PATH = '/company-chart/history';

    /**
     * Conservative cap below the server's observed ~350-bar ceiling.
     * Keeps a safety margin so a window always returns a full batch when
     * data exists, rather than landing exactly on the server's limit.
     */
    private const MAX_BARS_PER_REQUEST = 300;

    public function __construct(
        private readonly HttpFetcher $http,
    ) {}

    public function key(): string
    {
        return 'sharesansar_chart';
    }

    /**
     * @return list<HistoricalBar>
     */
    public function fetchHistory(string $symbol, int $days, ?Carbon $since = null): array
    {
        $symbol = strtoupper(trim($symbol));

        if ($days <= self::MAX_BARS_PER_REQUEST) {
            return $this->fetchWindow($symbol, Carbon::now()->endOfDay(), $days);
        }

        // The API caps at MAX_BARS_PER_REQUEST bars per request. Walk backwards
        // through time: each window's `to` is set to one day before the earliest
        // bar the previous window returned.
        $allBars = [];
        $to = Carbon::now()->endOfDay();
        $remaining = $days;

        while ($remaining > 0) {
            $windowSize = min(self::MAX_BARS_PER_REQUEST, $remaining);
            $window = $this->fetchWindow($symbol, $to, $windowSize);

            if ($window === []) {
                break;
            }

            $allBars = array_merge($allBars, $window);
            $remaining -= count($window);

            // If the window came back short, the source has no older data.
            if (count($window) < $windowSize) {
                break;
            }

            // Already as deep as the caller needs. `$days` is a bar count and
            // the drip passes calendar days for it, so without this stop a
            // 5-year target walked on to ~7 years of sessions: two or three
            // extra requests per scrip for bars nobody asked for.
            if ($since !== null && $window[0]->date->lte($since)) {
                break;
            }

            // Oldest bar is first (fetchWindow guarantees oldest-first).
            $to = $window[0]->date->copy()->subDay()->endOfDay();
        }

        // Deduplicate by date (windows may overlap by one day at boundaries)
        // and guarantee oldest-first order.
        $byDate = [];
        foreach ($allBars as $bar) {
            $key = $bar->date->toDateString();
            if (! isset($byDate[$key])) {
                $byDate[$key] = $bar;
            }
        }
        ksort($byDate);

        return array_values($byDate);
    }

    /**
     * Fetch a single window of up to $countback bars ending at $to.
     *
     * @return list<HistoricalBar>
     */
    private function fetchWindow(string $symbol, Carbon $to, int $countback): array
    {
        $base = $this->baseUrl();
        // Ask for a wider calendar window than the trading-session count:
        // NEPSE trades ~5 days a week and closes for public holidays, so
        // ~200 calendar days are needed to cover 120 sessions.
        $from = $to->copy()->subDays((int) ceil($countback * 1.8) + 30);

        try {
            $body = $this->http->get(
                $this->key(),
                $base.self::PATH,
                [
                    'symbol' => $symbol,
                    'resolution' => '1D',
                    'from' => $from->getTimestamp(),
                    'to' => $to->getTimestamp(),
                    // Without countback the endpoint answers "no_data" for
                    // every symbol; it is what actually drives the query.
                    'countback' => $countback,
                ],
                ['Referer' => $base.'/company/'.strtolower($symbol)],
            );
        } catch (ScrapeException $e) {
            Log::channel('scraper')->warning('History fetch failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            throw ScrapeException::markupChanged($this->key(), 'chart feed did not return JSON');
        }

        $status = $payload['s'] ?? 'error';

        if ($status === 'no_data') {
            return [];
        }

        if ($status !== 'ok') {
            Log::channel('scraper')->warning('History feed returned an error status', [
                'symbol' => $symbol,
                'status' => $status,
            ]);

            return [];
        }

        foreach (['t', 'o', 'h', 'l', 'c', 'v'] as $key) {
            if (! isset($payload[$key]) || ! is_array($payload[$key])) {
                throw ScrapeException::markupChanged(
                    $this->key(),
                    "chart feed response is missing the '{$key}' series",
                );
            }
        }

        $timestamps = $payload['t'];
        $count = count($timestamps);
        $bars = [];

        for ($i = 0; $i < $count; $i++) {
            $close = $this->number($payload['c'][$i] ?? null);

            // A bar without a close is not usable; the rest are optional.
            if ($close === null || $close <= 0) {
                continue;
            }

            $bars[] = new HistoricalBar(
                // The feed timestamps each bar at midnight UTC of the session.
                date: Carbon::createFromTimestampUTC((int) $timestamps[$i])->startOfDay(),
                close: $close,
                open: $this->number($payload['o'][$i] ?? null),
                high: $this->number($payload['h'][$i] ?? null),
                low: $this->number($payload['l'][$i] ?? null),
                volume: (int) round((float) ($payload['v'][$i] ?? 0)),
            );
        }

        // Guarantee oldest-first regardless of how the feed ordered them.
        usort($bars, static fn (HistoricalBar $a, HistoricalBar $b): int => $a->date <=> $b->date);

        return $bars;
    }

    private function number(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return $float > 0 ? round($float, 2) : null;
    }

    private function baseUrl(): string
    {
        $configured = (string) config('nepse.source_config.sharesansar.today_price_url');
        $parts = parse_url($configured);

        if (isset($parts['scheme'], $parts['host'])) {
            return $parts['scheme'].'://'.$parts['host'];
        }

        return 'https://www.sharesansar.com';
    }
}
