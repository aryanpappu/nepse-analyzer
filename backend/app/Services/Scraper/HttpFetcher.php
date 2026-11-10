<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Exceptions\ScrapeException;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Shared HTTP layer for every crawler.
 *
 * Centralises the things that are easy to get wrong per-source: a browser-like
 * User-Agent (these sites 403 the default Guzzle UA), retry with backoff on
 * transient failures, and a politeness delay so we do not hammer a free public
 * site during a full-market crawl.
 */
final class HttpFetcher
{
    /** Unix timestamp (ms) of the last request per host, for rate limiting. */
    private array $lastRequestAt = [];

    /**
     * Fetch a URL and return a DomCrawler over the response body.
     *
     * @param  array<string, string|int>  $query
     *
     * @throws ScrapeException
     */
    public function crawl(string $source, string $url, array $query = [], array $headers = [], ?CookieJar $cookies = null): Crawler
    {
        return new Crawler($this->get($source, $url, $query, $headers, $cookies), $url);
    }

    /**
     * A cookie jar that can be threaded through several calls.
     *
     * Some endpoints are only reachable as a browser would reach them: fetch the
     * page to pick up a session cookie and a CSRF token, then post back with
     * both. Sharing one jar across those calls is what makes that possible.
     */
    public function newSession(): CookieJar
    {
        return new CookieJar;
    }

    /**
     * Fetch a URL and return the raw response body.
     *
     * @param  array<string, string|int>  $query
     *
     * @throws ScrapeException
     */
    public function get(string $source, string $url, array $query = [], array $headers = [], ?CookieJar $cookies = null): string
    {
        return $this->send(
            $source,
            $url,
            $headers,
            $cookies,
            // Only pass the query array when there is one. Guzzle rebuilds the
            // query string from this argument, so handing it an empty array
            // strips any params already in $url — which silently turned every
            // "?symbol=X" company page into the generic one.
            fn (PendingRequest $request) => $request->get($url, $query === [] ? null : $query),
        );
    }

    /**
     * Post a form-encoded payload and return the raw response body.
     *
     * @param  array<string, string|int>  $payload
     *
     * @throws ScrapeException
     */
    public function post(string $source, string $url, array $payload = [], array $headers = [], ?CookieJar $cookies = null): string
    {
        return $this->send(
            $source,
            $url,
            $headers,
            $cookies,
            fn (PendingRequest $request) => $request->asForm()->post($url, $payload),
        );
    }

    /**
     * @param  callable(PendingRequest): Response  $dispatch
     *
     * @throws ScrapeException
     */
    private function send(string $source, string $url, array $headers, ?CookieJar $cookies, callable $dispatch): string
    {
        $config = config('nepse.http');

        $this->throttle($url, (int) $config['request_delay_ms']);

        try {
            $request = Http::withHeaders(array_merge([
                'User-Agent' => $config['user_agent'],
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                // Some of these sites gate content behind a same-site referer.
                'Referer' => $this->origin($url),
                'Cache-Control' => 'no-cache',
            ], $headers))
                ->withOptions(array_filter([
                    'verify' => (bool) $config['verify_ssl'],
                    'cookies' => $cookies,
                ], static fn (mixed $value): bool => $value !== null))
                ->timeout((int) $config['timeout'])
                ->connectTimeout((int) $config['connect_timeout'])
                // Retry only transient failures; a 404 will never succeed on
                // retry and should surface immediately.
                ->retry(
                    (int) $config['retries'],
                    (int) $config['retry_delay_ms'],
                    function (\Throwable $exception): bool {
                        if ($exception instanceof ConnectionException) {
                            return true;
                        }

                        if ($exception instanceof RequestException) {
                            $status = $exception->response->status();

                            return $status === 429 || $status >= 500;
                        }

                        return false;
                    },
                    throw: false,
                );

            $response = $dispatch($request);
        } catch (ConnectionException $e) {
            throw ScrapeException::requestFailed($source, $url, 'connection error: '.$e->getMessage(), $e);
        }

        if ($response->failed()) {
            throw ScrapeException::requestFailed($source, $url, 'HTTP '.$response->status());
        }

        $body = $response->body();

        if (trim($body) === '') {
            throw ScrapeException::requestFailed($source, $url, 'empty response body');
        }

        Log::channel('scraper')->debug('Fetched page', [
            'source' => $source,
            'url' => $url,
            'status' => $response->status(),
            'bytes' => strlen($body),
        ]);

        return $body;
    }

    /**
     * Sleep just long enough to honour the configured per-host delay.
     */
    private function throttle(string $url, int $delayMs): void
    {
        if ($delayMs <= 0) {
            return;
        }

        $host = parse_url($url, PHP_URL_HOST) ?: 'unknown';
        $now = (int) (microtime(true) * 1000);
        $last = $this->lastRequestAt[$host] ?? null;

        if ($last !== null) {
            $elapsed = $now - $last;

            if ($elapsed < $delayMs) {
                usleep(($delayMs - $elapsed) * 1000);
            }
        }

        $this->lastRequestAt[$host] = (int) (microtime(true) * 1000);
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        return $parts['scheme'].'://'.$parts['host'].'/';
    }
}
