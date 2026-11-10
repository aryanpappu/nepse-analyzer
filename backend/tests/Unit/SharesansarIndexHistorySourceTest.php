<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ScrapeException;
use App\Services\Scraper\HttpFetcher;
use App\Services\Scraper\IndexCatalog;
use App\Services\Scraper\Sources\SharesansarIndexHistorySource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two JSON fixtures here are real captures of ShareSansar's
 * `/index-history-data` feed for NEPSE Index (index_id=12) over
 * 2026-01-01..2026-04-15 — a genuine 63-row, two-page range (the server caps
 * `length` at 50, confirmed empirically: higher values silently return zero
 * rows). Re-capture them from the endpoint if its response shape changes; do
 * not hand-edit them.
 */
final class SharesansarIndexHistorySourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('nepse.http.request_delay_ms', 0);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/Scraper/'.$name);
    }

    #[Test]
    public function it_lists_every_index_share_sansar_assigns_an_id_to(): void
    {
        $source = new SharesansarIndexHistorySource(new HttpFetcher);

        $this->assertSame(IndexCatalog::CANONICAL_NAMES, $source->availableIndices());
    }

    #[Test]
    public function it_pages_through_the_datatables_feed_until_it_runs_out_of_rows(): void
    {
        Http::fakeSequence()
            ->push($this->fixture('index-history-nepse-page1.json'))
            ->push($this->fixture('index-history-nepse-page2.json'));

        $source = new SharesansarIndexHistorySource(new HttpFetcher);

        $bars = $source->fetchIndexHistory(
            'NEPSE Index',
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-04-15'),
        );

        // 50 + 13 across the two real pages.
        $this->assertCount(63, $bars);

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            return $request['index_id'] === 12
                && $request['start'] === 0
                && $request['length'] === 50;
        });
        Http::assertSent(function (Request $request): bool {
            return $request['index_id'] === 12 && $request['start'] === 50;
        });
    }

    #[Test]
    public function it_returns_bars_oldest_first_regardless_of_page_order(): void
    {
        Http::fakeSequence()
            ->push($this->fixture('index-history-nepse-page1.json'))
            ->push($this->fixture('index-history-nepse-page2.json'));

        $source = new SharesansarIndexHistorySource(new HttpFetcher);

        $bars = $source->fetchIndexHistory(
            'NEPSE Index',
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-04-15'),
        );

        $this->assertSame('2026-01-01', $bars[0]->date->toDateString());
        $this->assertSame('2026-04-15', $bars[count($bars) - 1]->date->toDateString());

        for ($i = 1; $i < count($bars); $i++) {
            $this->assertTrue($bars[$i - 1]->date->lessThanOrEqualTo($bars[$i]->date));
        }
    }

    #[Test]
    public function it_maps_every_field_the_feed_publishes(): void
    {
        Http::fakeSequence()
            ->push($this->fixture('index-history-nepse-page1.json'))
            ->push($this->fixture('index-history-nepse-page2.json'));

        $source = new SharesansarIndexHistorySource(new HttpFetcher);

        $bars = $source->fetchIndexHistory(
            'NEPSE Index',
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-04-15'),
        );

        $first = $bars[0];

        $this->assertSame('NEPSE Index', $first->name);
        $this->assertSame(2620.92, $first->value);
        $this->assertSame(2634.96, $first->open);
        $this->assertSame(2637.10, $first->high);
        $this->assertSame(2613.50, $first->low);
        $this->assertSame(-12.83, $first->changeAmount);
        $this->assertSame(-0.48, $first->changePercent);
        $this->assertSame(4713905470.50, $first->totalTurnover);
    }

    #[Test]
    public function it_rejects_an_unknown_index_name(): void
    {
        $source = new SharesansarIndexHistorySource(new HttpFetcher);

        $this->expectException(ScrapeException::class);

        $source->fetchIndexHistory('Not A Real Index', Carbon::parse('2026-01-01'), Carbon::parse('2026-04-15'));
    }

    #[Test]
    public function it_throws_when_the_feed_stops_returning_the_expected_json_shape(): void
    {
        Http::fake(['*' => Http::response('not json')]);

        $source = new SharesansarIndexHistorySource(new HttpFetcher);

        $this->expectException(ScrapeException::class);

        $source->fetchIndexHistory('NEPSE Index', Carbon::parse('2026-01-01'), Carbon::parse('2026-04-15'));
    }
}
