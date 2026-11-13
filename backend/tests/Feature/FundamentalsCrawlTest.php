<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ScrapeRun;
use App\Models\Sector;
use App\Models\Stock;
use App\Models\StockFundamental;
use App\Services\Scraper\NepseScraperService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FeatureTestCase;

/**
 * The fundamentals crawl asks its sources side by side and shares the
 * ShareSansar company page between the two sources that read it. Neither may
 * change what gets persisted: the merge still runs in configured source order,
 * and the result must be identical with cross-host overlap switched off.
 */
final class FundamentalsCrawlTest extends FeatureTestCase
{
    /** @var list<string> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('nepse.http.request_delay_ms', 0);
        config()->set('nepse.http.retries', 1);
        config()->set('nepse.fundamental_sources', ['sharesansar_quarterly', 'merolagani', 'sharesansar']);

        $fixture = (string) file_get_contents(__DIR__.'/../Fixtures/Scraper/quarterly-report-bank.html');

        Http::fake(function (Request $request) use ($fixture) {
            $url = $request->url();
            $this->requests[] = $request->method().' '.$url;

            if (str_contains($url, 'today-share-price')) {
                return Http::response(
                    '<html><head><meta name="_token" content="tok"></head><body><script>'
                    .'var c=[{"id":7,"symbol":"NABIL","companyname":"Nabil Bank Limited"}];</script></body></html>'
                );
            }

            if (str_contains($url, 'company-quarterly-report')) {
                // The stored sector is stale, so the first POST selects the
                // wrong template and comes back empty, as the live site does.
                return Http::response(($request->data()['sector'] ?? null) === 'Commercial Banks'
                    ? $fixture
                    : '<html><table></table></html>');
            }

            if (str_contains($url, 'sharesansar.com/company/nabil')) {
                return Http::response(
                    '<html><span id="sector">Commercial Banks</span><table>'
                    .'<tr><td>Sector</td><td>Commercial Banks</td></tr>'
                    .'<tr><td>Listed Shares</td><td>270,563,113</td></tr>'
                    .'<tr><td>Cash Dividend</td><td>12.5</td></tr>'
                    .'<tr><td>Bonus Share</td><td>5</td></tr>'
                    .'</table></html>'
                );
            }

            // Merolagani is down: the other sources' data must still land.
            return Http::response('', 503);
        });
    }

    /** @return array<string, array{bool}> */
    public static function overlapModes(): array
    {
        return ['hosts overlapped' => [true], 'strictly serial' => [false]];
    }

    #[DataProvider('overlapModes')]
    public function test_it_merges_every_source_and_fetches_the_company_page_once(bool $concurrent): void
    {
        config()->set('nepse.http.concurrent_hosts', $concurrent);

        $sector = Sector::factory()->create(['name' => 'Hydro Power']);
        $stock = Stock::factory()->symbol('NABIL')->inSector($sector)->create();

        $run = app(NepseScraperService::class)->scrapeFundamentals(['NABIL']);

        $this->assertSame(ScrapeRun::STATUS_SUCCESS, $run->status);

        $fundamental = StockFundamental::where('stock_id', $stock->id)->firstOrFail();

        // From the quarterly report (first source)...
        $this->assertNotNull($fundamental->eps);
        $this->assertNotNull($fundamental->book_value);
        // ...and the dividend split only the ShareSansar company page carries.
        $this->assertEqualsWithDelta(12.5, (float) $fundamental->cash_dividend_percent, 0.001);

        $companyPageFetches = array_filter(
            $this->requests,
            static fn (string $r): bool => str_contains($r, 'sharesansar.com/company/nabil'),
        );
        $this->assertCount(1, $companyPageFetches, 'both ShareSansar sources read one fetch of the company page');

        $posts = array_filter($this->requests, static fn (string $r): bool => str_starts_with($r, 'POST'));
        $this->assertCount(2, $posts, 'stale sector hint, then the discovered one');
    }
}
