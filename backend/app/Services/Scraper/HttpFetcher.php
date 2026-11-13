<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Exceptions\ScrapeException;
use Closure;
use Fiber;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise\Is;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Promises\LazyPromise;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SplObjectStorage;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Shared HTTP layer for every crawler.
 *
 * Centralises the things that are easy to get wrong per-source: a browser-like
 * User-Agent (these sites 403 the default Guzzle UA), retry with backoff on
 * transient failures, and a politeness delay so we do not hammer a free public
 * site during a full-market crawl.
 *
 * Politeness is per host and has two parts, both enforced here and nowhere
 * else: consecutive requests to one host start at least `request_delay_ms`
 * apart, and a host never has more than one request in flight. What
 * {@see concurrently()} adds is overlap *between different hosts* — while
 * ShareSansar is answering, Merolagani can be too — which is where a
 * multi-source crawl used to spend most of its wall-clock time waiting.
 */
final class HttpFetcher
{
    /** How long a parked lane waits before re-checking a host another lane holds. */
    private const LANE_POLL_MS = 25;

    /** Unix timestamp (ms) of the last request per host, for rate limiting. */
    private array $lastRequestAt = [];

    /** @var array<string, true> Hosts with a request currently in flight. */
    private array $inFlight = [];

    /**
     * Bodies of recent GETs made with `reuse: true`, oldest first, so two
     * sources that need the same page in one pass fetch it once.
     *
     * @var array<string, array{body: string, at: float}>
     */
    private array $reusable = [];

    /** @var array<string, true> Reusable URLs some lane is fetching right now. */
    private array $reusePending = [];

    /** Shared curl multi handle while {@see concurrently()} is running. */
    private ?CurlMultiHandler $laneHandler = null;

    /** @var SplObjectStorage<Fiber, null>|null The lanes of the running {@see concurrently()}. */
    private ?SplObjectStorage $lanes = null;

    /**
     * Fetch a URL and return a DomCrawler over the response body.
     *
     * @param  array<string, string|int>  $query
     *
     * @throws ScrapeException
     */
    public function crawl(string $source, string $url, array $query = [], array $headers = [], ?CookieJar $cookies = null, bool $reuse = false): Crawler
    {
        return new Crawler($this->get($source, $url, $query, $headers, $cookies, $reuse), $url);
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
     * `$reuse` opts a GET into a short-lived in-memory memo, bounded by
     * `nepse.http.reuse_ttl_seconds` and `nepse.http.reuse_max_entries`. It is
     * for a static page two sources both read in the same pass — the
     * ShareSansar company page — and must never be set on a live page such as
     * today's prices, where a memoised body would be a stale snapshot. A failed
     * fetch is never memoised.
     *
     * @param  array<string, string|int>  $query
     *
     * @throws ScrapeException
     */
    public function get(string $source, string $url, array $query = [], array $headers = [], ?CookieJar $cookies = null, bool $reuse = false): string
    {
        if (! $reuse) {
            return $this->fetch($source, $url, $query, $headers, $cookies);
        }

        $key = $query === [] ? $url : $url.'?'.http_build_query($query);

        // Another lane is already fetching this exact page: wait for its body
        // rather than asking the host for it a second time.
        while (isset($this->reusePending[$key]) && $this->inLane()) {
            Fiber::suspend($this->nowMs() + self::LANE_POLL_MS);
        }

        $ttlMs = max(0, (int) config('nepse.http.reuse_ttl_seconds', 300)) * 1000;
        $hit = $this->reusable[$key] ?? null;

        if ($hit !== null && $this->nowMs() - $hit['at'] <= $ttlMs) {
            return $hit['body'];
        }

        $this->reusePending[$key] = true;

        try {
            $body = $this->fetch($source, $url, $query, $headers, $cookies);
        } finally {
            unset($this->reusePending[$key]);
        }

        unset($this->reusable[$key]);
        $this->reusable[$key] = ['body' => $body, 'at' => $this->nowMs()];

        // Insertion order is age order, so trimming from the front evicts the
        // stalest page. Bounded because this object is a container singleton
        // and lives as long as the queue worker does.
        $max = max(0, (int) config('nepse.http.reuse_max_entries', 8));

        while (count($this->reusable) > $max) {
            unset($this->reusable[array_key_first($this->reusable)]);
        }

        return $body;
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
     * Run tasks side by side, overlapping their HTTP waits across hosts.
     *
     * Each task runs in its own Fiber. Every request it makes through this
     * fetcher still passes the same per-host gate — the politeness delay plus
     * at most one request in flight per host — so two tasks that hit the same
     * host simply take turns, as they would have one after the other. Only
     * requests to *different* hosts overlap.
     *
     * A task that throws does not stop the others: its slot in the result
     * holds the Throwable instead of a value, for the caller to handle. With
     * `nepse.http.concurrent_hosts` off, or when nested, the tasks run one
     * after another and the result has the same shape.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, callable(): mixed>  $tasks
     * @return array<TKey, mixed> Each task's return value, or the Throwable it threw, in the order given.
     */
    public function concurrently(array $tasks): array
    {
        if ($this->lanes !== null || count($tasks) < 2 || ! (bool) config('nepse.http.concurrent_hosts', true)) {
            $results = [];

            foreach ($tasks as $key => $task) {
                try {
                    $results[$key] = $task();
                } catch (Throwable $e) {
                    $results[$key] = $e;
                }
            }

            return $results;
        }

        $this->laneHandler = new CurlMultiHandler([
            // Short, so a lane parked on its host's politeness delay is woken
            // close to on time while another lane's transfer is in progress.
            'select_timeout' => self::LANE_POLL_MS / 1000,
        ]);
        $this->lanes = new SplObjectStorage;

        $fibers = [];
        /** @var array<array-key, PromiseInterface|float> $waits A pending response, or a wake-up time in ms. */
        $waits = [];
        $results = [];

        try {
            foreach ($tasks as $key => $task) {
                $fiber = new Fiber(Closure::fromCallable($task));
                $this->lanes->attach($fiber);
                $fibers[$key] = $fiber;

                $this->advance($key, $fiber, static fn (): mixed => $fiber->start(), $waits, $results);
            }

            while ($waits !== []) {
                $awaitingResponse = false;
                $nextWake = null;

                foreach ($waits as $key => $wait) {
                    $fiber = $fibers[$key];

                    if ($wait instanceof PromiseInterface) {
                        if (! Is::settled($wait)) {
                            $awaitingResponse = true;

                            continue;
                        }

                        unset($waits[$key]);

                        try {
                            $value = $wait->wait();
                        } catch (Throwable $e) {
                            $this->advance($key, $fiber, static fn (): mixed => $fiber->throw($e), $waits, $results);

                            continue;
                        }

                        $this->advance($key, $fiber, static fn (): mixed => $fiber->resume($value), $waits, $results);

                        continue;
                    }

                    if ($this->nowMs() >= $wait) {
                        unset($waits[$key]);
                        $this->advance($key, $fiber, static fn (): mixed => $fiber->resume(), $waits, $results);

                        continue;
                    }

                    $nextWake = $nextWake === null ? $wait : min($nextWake, $wait);
                }

                if ($waits === []) {
                    break;
                }

                if ($awaitingResponse) {
                    // Drives every lane's transfer at once; blocks at most
                    // select_timeout, then the parked lanes are re-checked.
                    $this->laneHandler->tick();
                } elseif ($nextWake !== null) {
                    usleep(max(1000, (int) (($nextWake - $this->nowMs()) * 1000)));
                }
            }
        } finally {
            $this->laneHandler = null;
            $this->lanes = null;
        }

        $ordered = [];

        foreach (array_keys($tasks) as $key) {
            $ordered[$key] = $results[$key] ?? null;
        }

        return $ordered;
    }

    /**
     * Step one lane and record where it stopped: finished, failed, waiting on
     * a response, or parked until a time.
     *
     * @param  array<array-key, PromiseInterface|float>  $waits
     * @param  array<array-key, mixed>  $results
     */
    private function advance(int|string $key, Fiber $fiber, Closure $step, array &$waits, array &$results): void
    {
        try {
            $suspended = $step();
        } catch (Throwable $e) {
            $results[$key] = $e;

            return;
        }

        if ($fiber->isTerminated()) {
            $results[$key] = $fiber->getReturn();

            return;
        }

        $waits[$key] = $suspended instanceof PromiseInterface ? $suspended : (float) $suspended;
    }

    private function inLane(): bool
    {
        $current = Fiber::getCurrent();

        return $current !== null && $this->lanes !== null && $this->lanes->contains($current);
    }

    private function nowMs(): float
    {
        return microtime(true) * 1000;
    }

    /**
     * @param  array<string, string|int>  $query
     *
     * @throws ScrapeException
     */
    private function fetch(string $source, string $url, array $query, array $headers, ?CookieJar $cookies): string
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
     * @param  callable(PendingRequest): mixed  $dispatch  A Response, or inside a lane a promise of one.
     *
     * @throws ScrapeException
     */
    private function send(string $source, string $url, array $headers, ?CookieJar $cookies, callable $dispatch): string
    {
        $config = config('nepse.http');
        $host = parse_url($url, PHP_URL_HOST) ?: 'unknown';

        $this->throttle($host, (int) $config['request_delay_ms']);
        $this->inFlight[$host] = true;

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
                    function (Throwable $exception): bool {
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

            $response = $this->inLane()
                ? $this->awaitInLane($dispatch($request->setHandler($this->laneHandler)->async()))
                : $dispatch($request);
        } catch (ConnectionException $e) {
            throw ScrapeException::requestFailed($source, $url, 'connection error: '.$e->getMessage(), $e);
        } finally {
            unset($this->inFlight[$host]);
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
     * Hand a pending request to the {@see concurrently()} scheduler and resume
     * with its outcome.
     *
     * Laravel's async client resolves a connection failure to the exception
     * object instead of rejecting, so both shapes are turned back into the
     * throw the synchronous path would have produced.
     */
    private function awaitInLane(mixed $pending): Response
    {
        if ($pending instanceof LazyPromise && $pending->promiseNeedsBuilt()) {
            $pending = $pending->buildPromise();
        }

        $outcome = $pending instanceof PromiseInterface ? Fiber::suspend($pending) : $pending;

        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        return $outcome;
    }

    /**
     * Wait just long enough to honour the configured per-host delay and —
     * inside {@see concurrently()} — until no other lane has a request to this
     * host in flight. A lane parks rather than sleeps, so the other lanes keep
     * going meanwhile; outside a lane this is the plain blocking sleep it
     * always was.
     */
    private function throttle(string $host, int $delayMs): void
    {
        while (true) {
            $now = $this->nowMs();
            $wait = 0.0;

            if (isset($this->inFlight[$host])) {
                $wait = (float) self::LANE_POLL_MS;
            } elseif ($delayMs > 0 && isset($this->lastRequestAt[$host])) {
                $wait = max(0.0, $delayMs - ($now - $this->lastRequestAt[$host]));
            }

            if ($wait <= 0) {
                break;
            }

            if ($this->inLane()) {
                Fiber::suspend($now + $wait);
            } else {
                usleep((int) ($wait * 1000));
            }
        }

        $this->lastRequestAt[$host] = $this->nowMs();
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
