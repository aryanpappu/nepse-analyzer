<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ScrapeRun;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use App\Services\Scraper\NepseScraperService;
use App\Services\Scraper\PriceRow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\FeatureTestCase;

/**
 * The live pass writes a provisional bar only on a day the page itself says
 * is a session, never over a settled close; and the staleness banner stops
 * blaming the scheduler when the crawler is demonstrably still succeeding.
 */
final class LivePriceScrapeTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('nepse.http.request_delay_ms', 0);
        config()->set('nepse.http.retries', 1);
        config()->set('nepse.live.min_rows', 5);

        // Captured 2026-11-17 13:01 — see tests/Unit/SharesansarLivePricesTest.
        $fixture = (string) file_get_contents(__DIR__.'/../Fixtures/Scraper/live-trading-prices.html');
        Http::fake(['*' => Http::response($fixture)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function at(string $moment): void
    {
        Carbon::setTestNow(Carbon::parse($moment, (string) config('nepse.timezone')));
    }

    public function test_a_session_day_gets_a_provisional_bar_tagged_live(): void
    {
        $this->at('2026-11-17 13:05');

        $run = app(NepseScraperService::class)->scrapeLivePrices();

        $this->assertSame(ScrapeRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(12, StockPrice::count());
        $this->assertSame(12, StockPrice::where('source', NepseScraperService::LIVE_SOURCE)->count());
        $this->assertFalse(app(NepseScraperService::class)->hasSettledPrices(Carbon::now()));

        $adbl = StockPrice::whereHas('stock', fn ($q) => $q->where('symbol', 'ADBL'))->firstOrFail();
        $this->assertSame(331.0, (float) $adbl->close);
    }

    public function test_a_page_stamped_with_an_earlier_session_writes_nothing(): void
    {
        // A holiday, a weekend or before the open: the page still shows the
        // last session, so today is not one (yet).
        $this->at('2026-11-18 11:15');

        $run = app(NepseScraperService::class)->scrapeLivePrices();

        $this->assertSame(ScrapeRun::STATUS_SKIPPED, $run->status);
        $this->assertSame(0, StockPrice::count());
    }

    public function test_a_settled_close_is_never_overwritten_by_live_figures(): void
    {
        $this->at('2026-11-17 15:40');

        $stock = Stock::factory()->create(['symbol' => 'ADBL']);
        StockPrice::factory()->for($stock)->on(Carbon::today())->create(['close' => 333, 'source' => 'sharesansar']);

        $run = app(NepseScraperService::class)->scrapeLivePrices();

        $this->assertSame(ScrapeRun::STATUS_SKIPPED, $run->status);
        $this->assertSame(1, StockPrice::count());
        $this->assertSame(333.0, (float) StockPrice::firstOrFail()->close);
    }

    public function test_a_closed_market_is_not_reported_as_a_stopped_scheduler(): void
    {
        $this->at('2026-11-17 12:55');
        Sanctum::actingAs(User::factory()->admin()->create());

        StockPrice::factory()->for(Stock::factory())->on(Carbon::parse('2026-11-13'))->create();
        ScrapeRun::create([
            'type' => 'daily_prices', 'source' => 'sharesansar', 'trade_date' => '2026-11-13',
            'status' => ScrapeRun::STATUS_SUCCESS, 'started_at' => now()->subHour(), 'finished_at' => now()->subHour(),
        ]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('data.status.level', 'ok')
            ->assertJsonPath('data.status.headline', 'No new session since 13 Nov')
            ->assertJsonPath('data.status.action', null);
    }

    public function test_a_crawler_that_has_not_succeeded_for_a_day_is_still_flagged(): void
    {
        $this->at('2026-11-17 12:55');
        Sanctum::actingAs(User::factory()->admin()->create());

        StockPrice::factory()->for(Stock::factory())->on(Carbon::parse('2026-11-13'))->create();
        ScrapeRun::create([
            'type' => 'daily_prices', 'source' => 'sharesansar', 'trade_date' => '2026-11-13',
            'status' => ScrapeRun::STATUS_SUCCESS, 'started_at' => now()->subDays(3), 'finished_at' => now()->subDays(3),
        ]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('data.status.level', 'stale')
            ->assertJsonPath('data.status.action', 'php artisan schedule:work');
    }

    public function test_a_settled_pass_still_serving_yesterday_does_not_move_a_live_quote_backwards(): void
    {
        $this->at('2026-11-17 13:05');
        app(NepseScraperService::class)->scrapeLivePrices();

        // What a settled pass sees before the page is republished: the
        // previous session (13 Nov), whose row it simply re-saves.
        $persist = new \ReflectionMethod(NepseScraperService::class, 'persistPrices');
        $persist->invoke(
            app(NepseScraperService::class),
            [new PriceRow(symbol: 'ADBL', name: null, sector: null, close: 335.0, previousClose: 330.0, volume: 1000)],
            Carbon::parse('2026-11-13'),
            'sharesansar',
        );

        $adbl = Stock::where('symbol', 'ADBL')->firstOrFail();
        $this->assertSame(331.0, (float) $adbl->last_price);
        $this->assertSame('2026-11-17', Carbon::parse((string) $adbl->last_traded_on)->toDateString());
    }
}
