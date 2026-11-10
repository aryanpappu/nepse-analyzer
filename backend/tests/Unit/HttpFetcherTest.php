<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scraper\HttpFetcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpFetcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Throttling would make every test sleep for over a second.
        config()->set('nepse.http.request_delay_ms', 0);
    }

    /**
     * Guzzle rebuilds the query string from the array passed to get(), so
     * handing it an empty array wipes params already present in the URL.
     *
     * This cost real data: every "?symbol=X" company page silently became the
     * generic one, so merolagani returned the same empty shell for every scrip
     * and EPS, P/E and book value stayed null across the whole market.
     */
    public function test_it_preserves_query_parameters_already_in_the_url(): void
    {
        Http::fake(['*' => Http::response('<html>ok</html>')]);

        (new HttpFetcher)->get('merolagani', 'https://merolagani.com/CompanyDetail.aspx?symbol=NABIL');

        Http::assertSent(function (Request $request): bool {
            $this->assertStringContainsString('symbol=NABIL', $request->url());

            return true;
        });
    }

    public function test_an_explicit_query_array_is_still_applied(): void
    {
        Http::fake(['*' => Http::response('<html>ok</html>')]);

        (new HttpFetcher)->get('sharesansar', 'https://example.test/page', ['page' => 2]);

        Http::assertSent(function (Request $request): bool {
            $this->assertStringContainsString('page=2', $request->url());

            return true;
        });
    }

    public function test_different_symbols_produce_different_urls(): void
    {
        Http::fake(['*' => Http::response('<html>ok</html>')]);

        $fetcher = new HttpFetcher;

        foreach (['NABIL', 'SANIMA'] as $symbol) {
            $fetcher->get('merolagani', "https://merolagani.com/CompanyDetail.aspx?symbol={$symbol}");
        }

        // The bug's clearest symptom was every scrip fetching the same URL.
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'symbol=NABIL'));
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'symbol=SANIMA'));
    }
}
