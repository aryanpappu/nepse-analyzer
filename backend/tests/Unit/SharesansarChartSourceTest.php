<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scraper\HttpFetcher;
use App\Services\Scraper\Sources\SharesansarChartSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The drip asks for its target in calendar days, which the source reads as a
 * bar count — so without an early stop it paged on through years of history
 * beyond the target, one request per 300 bars.
 */
final class SharesansarChartSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('nepse.http.request_delay_ms', 0);
        Carbon::setTestNow('2026-11-13 12:00:00');

        // A feed with unlimited daily history: each window returns exactly
        // `countback` consecutive days ending at `to`.
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $to = Carbon::createFromTimestampUTC((int) $query['to'])->startOfDay();
            $t = [];

            for ($i = (int) $query['countback'] - 1; $i >= 0; $i--) {
                $t[] = $to->copy()->subDays($i)->getTimestamp();
            }

            $n = count($t);

            return Http::response([
                's' => 'ok', 't' => $t,
                'o' => array_fill(0, $n, 100), 'h' => array_fill(0, $n, 101),
                'l' => array_fill(0, $n, 99), 'c' => array_fill(0, $n, 100),
                'v' => array_fill(0, $n, 10),
            ]);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_without_a_since_date_it_walks_the_whole_bar_count(): void
    {
        $bars = (new SharesansarChartSource(new HttpFetcher))->fetchHistory('NABIL', 1825);

        $this->assertCount(1825, $bars);
        Http::assertSentCount(7);
    }

    public function test_it_stops_paging_once_the_target_date_is_reached(): void
    {
        $since = Carbon::parse('2026-11-13')->subDays(700);

        $bars = (new SharesansarChartSource(new HttpFetcher))->fetchHistory('NABIL', 1825, $since);

        // 300 + 300 + 300 bars reach back past 700 days; no fourth request.
        Http::assertSentCount(3);
        $this->assertTrue($bars[0]->date->lte($since), 'history still reaches the target');
        $this->assertTrue($bars[0]->date->lt($bars[count($bars) - 1]->date), 'still oldest first');
    }
}
