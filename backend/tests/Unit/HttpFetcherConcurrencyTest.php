<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ScrapeException;
use App\Services\Scraper\HttpFetcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Cross-host overlap must never cost per-host politeness: requests to one host
 * stay spaced by request_delay_ms however many lanes ask for it, while lanes
 * on different hosts wait at the same time.
 */
final class HttpFetcherConcurrencyTest extends TestCase
{
    /** @var array<string, list<float>> host => request start times (ms) */
    private array $startedAt = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('nepse.http.request_delay_ms', 0);
        config()->set('nepse.http.concurrent_hosts', true);

        Http::fake(function (Request $request) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);
            $this->startedAt[$host][] = microtime(true) * 1000;

            return str_contains($request->url(), 'broken')
                ? Http::response('', 500)
                : Http::response('<html>'.$request->url().'</html>');
        });
    }

    public function test_results_come_back_in_task_order_with_failures_captured(): void
    {
        config()->set('nepse.http.retries', 1);
        $fetcher = new HttpFetcher;

        $results = $fetcher->concurrently([
            'a' => fn (): string => $fetcher->get('s', 'https://one.test/a'),
            'b' => fn (): string => $fetcher->get('s', 'https://two.test/broken'),
            'c' => static fn (): int => 42,
            'd' => static fn () => throw new RuntimeException('boom'),
        ]);

        $this->assertSame(['a', 'b', 'c', 'd'], array_keys($results));
        $this->assertStringContainsString('one.test/a', $results['a']);
        $this->assertInstanceOf(ScrapeException::class, $results['b']);
        $this->assertSame(42, $results['c']);
        $this->assertInstanceOf(RuntimeException::class, $results['d']);
    }

    public function test_lanes_on_the_same_host_keep_the_politeness_delay(): void
    {
        config()->set('nepse.http.request_delay_ms', 150);
        $fetcher = new HttpFetcher;

        $fetcher->concurrently([
            fn (): string => $fetcher->get('s', 'https://same.test/1'),
            fn (): string => $fetcher->get('s', 'https://same.test/2'),
            fn (): string => $fetcher->get('s', 'https://same.test/3'),
        ]);

        $starts = $this->startedAt['same.test'];
        sort($starts);

        $this->assertCount(3, $starts);

        for ($i = 1; $i < count($starts); $i++) {
            // A little slack for timer granularity, never a real overlap.
            $this->assertGreaterThanOrEqual(145, $starts[$i] - $starts[$i - 1]);
        }
    }

    public function test_lanes_on_different_hosts_wait_at_the_same_time(): void
    {
        config()->set('nepse.http.request_delay_ms', 200);
        $fetcher = new HttpFetcher;

        $began = microtime(true);

        $fetcher->concurrently([
            function () use ($fetcher): void {
                $fetcher->get('s', 'https://one.test/1');
                $fetcher->get('s', 'https://one.test/2');
            },
            function () use ($fetcher): void {
                $fetcher->get('s', 'https://two.test/1');
                $fetcher->get('s', 'https://two.test/2');
            },
        ]);

        $elapsedMs = (microtime(true) - $began) * 1000;

        // Serial would be ~400ms (one.test 0/200, two.test 200/400). Overlapped,
        // each host only waits on itself: ~200ms.
        $this->assertLessThan(350, $elapsedMs);

        foreach (['one.test', 'two.test'] as $host) {
            $this->assertGreaterThanOrEqual(195, $this->startedAt[$host][1] - $this->startedAt[$host][0]);
        }
    }

    public function test_disabled_runs_the_tasks_one_after_another(): void
    {
        config()->set('nepse.http.concurrent_hosts', false);
        config()->set('nepse.http.request_delay_ms', 200);
        $fetcher = new HttpFetcher;

        $began = microtime(true);

        $results = $fetcher->concurrently([
            function () use ($fetcher): string {
                $fetcher->get('s', 'https://one.test/1');

                return $fetcher->get('s', 'https://one.test/2');
            },
            fn (): string => $fetcher->get('s', 'https://two.test/1'),
        ]);

        $this->assertCount(2, $results);
        $this->assertGreaterThanOrEqual(195, (microtime(true) - $began) * 1000);
    }

    public function test_a_reusable_page_is_fetched_once_across_lanes(): void
    {
        config()->set('nepse.http.request_delay_ms', 50);
        $fetcher = new HttpFetcher;

        $results = $fetcher->concurrently([
            fn (): string => $fetcher->get('a', 'https://same.test/company/nabil', reuse: true),
            fn (): string => $fetcher->get('b', 'https://same.test/company/nabil', reuse: true),
        ]);

        $this->assertSame($results[0], $results[1]);
        $this->assertCount(1, $this->startedAt['same.test']);
    }

    public function test_reuse_is_opt_in_and_bounded_by_its_ttl(): void
    {
        $fetcher = new HttpFetcher;

        $fetcher->get('s', 'https://same.test/live');
        $fetcher->get('s', 'https://same.test/live');
        $this->assertCount(2, $this->startedAt['same.test'], 'a plain GET is never memoised');

        $fetcher->get('s', 'https://same.test/company/x', reuse: true);
        $fetcher->get('s', 'https://same.test/company/x', reuse: true);
        $this->assertCount(3, $this->startedAt['same.test']);

        config()->set('nepse.http.reuse_ttl_seconds', 0);
        usleep(2000);
        $fetcher->get('s', 'https://same.test/company/x', reuse: true);
        $this->assertCount(4, $this->startedAt['same.test'], 'an expired body is fetched again');
    }

    public function test_reuse_keeps_only_the_newest_entries(): void
    {
        config()->set('nepse.http.reuse_max_entries', 1);
        $fetcher = new HttpFetcher;

        $fetcher->get('s', 'https://same.test/company/a', reuse: true);
        $fetcher->get('s', 'https://same.test/company/b', reuse: true);
        $fetcher->get('s', 'https://same.test/company/a', reuse: true);

        $this->assertCount(3, $this->startedAt['same.test']);
    }

    public function test_a_failed_reusable_fetch_is_not_memoised(): void
    {
        config()->set('nepse.http.retries', 1);
        $fetcher = new HttpFetcher;

        foreach ([1, 2] as $attempt) {
            try {
                $fetcher->get('s', 'https://same.test/broken', reuse: true);
                $this->fail('expected the 500 to surface');
            } catch (ScrapeException) {
                // expected
            }
        }

        $this->assertCount(2, $this->startedAt['same.test']);
    }
}
