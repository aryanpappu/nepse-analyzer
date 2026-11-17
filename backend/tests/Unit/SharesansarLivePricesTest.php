<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ScrapeException;
use App\Services\Scraper\HttpFetcher;
use App\Services\Scraper\PriceRow;
use App\Services\Scraper\PriceSnapshot;
use App\Services\Scraper\Sources\SharesansarSource;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Asserted against tests/Fixtures/Scraper/live-trading-prices.html, a verbatim
 * excerpt of ShareSansar's `/live-trading` page captured mid-session on
 * 2026-11-17 (the "As of" caption and the first 12 rows of its price table).
 * Re-capture it if the markup changes; do not hand-edit it.
 */
final class SharesansarLivePricesTest extends TestCase
{
    private function parse(string $html): PriceSnapshot
    {
        return (new SharesansarSource(new HttpFetcher))->parseLivePrices($html);
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/Scraper/live-trading-prices.html');
    }

    #[Test]
    public function it_dates_the_snapshot_from_the_as_of_stamp_including_the_time(): void
    {
        $snapshot = $this->parse($this->fixture());

        $this->assertSame('2026-11-17', $snapshot->tradeDate->toDateString());
        $this->assertSame('2026-11-17 13:01:00', $snapshot->asOf?->toDateTimeString());
        $this->assertTrue($snapshot->dateIsAuthoritative);
    }

    #[Test]
    public function it_reads_ltp_as_the_provisional_close_and_maps_every_column(): void
    {
        $snapshot = $this->parse($this->fixture());
        $this->assertCount(12, $snapshot->rows);

        $bySymbol = collect($snapshot->rows)->keyBy(fn (PriceRow $row): string => $row->symbol);
        $adbl = $bySymbol->get('ADBL');

        $this->assertNotNull($adbl);
        $this->assertSame(331.0, $adbl->close);
        $this->assertSame(335.0, $adbl->open);
        $this->assertSame(335.0, $adbl->high);
        $this->assertSame(327.0, $adbl->low);
        $this->assertSame(335.0, $adbl->previousClose);
        $this->assertSame(16683, $adbl->volume);
        // The percent column, not "Point Change" (-4.00 rupees).
        $this->assertSame(-1.19, $adbl->changePercent);
        // Not on the live page: left unknown for the settled table to fill.
        $this->assertNull($adbl->averagePrice);
        $this->assertNull($adbl->transactions);
        $this->assertSame(0.0, $adbl->turnover);
    }

    #[Test]
    public function it_refuses_a_page_without_an_as_of_stamp_rather_than_guessing_the_day(): void
    {
        $html = preg_replace('/<span id="dDate"[^>]*>[^<]*<\/span>/', '', $this->fixture());

        $this->expectException(ScrapeException::class);

        $this->parse((string) $html);
    }
}
