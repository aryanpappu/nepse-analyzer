<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scraper\IndexRow;
use App\Services\Scraper\LiveIndexParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Asserted against a real captured page rather than hand-written markup —
 * see tests/Fixtures/Scraper/live-trading.html, a verbatim excerpt of
 * ShareSansar's `/live-trading` response (the market-update widget block plus
 * the "As of" caption). Re-capture it if the widget's markup changes; do not
 * hand-edit it.
 */
final class LiveIndexParserTest extends TestCase
{
    private function fixture(): string
    {
        $path = __DIR__.'/../Fixtures/Scraper/live-trading.html';

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    #[Test]
    public function it_reads_the_session_date_from_the_as_of_caption(): void
    {
        $snapshot = (new LiveIndexParser)->parse($this->fixture());

        $this->assertNotNull($snapshot->tradeDate);
        $this->assertSame('2026-09-09', $snapshot->tradeDate->toDateString());
        $this->assertTrue($snapshot->dateIsAuthoritative);
    }

    #[Test]
    public function it_parses_every_widget_on_the_captured_page(): void
    {
        $snapshot = (new LiveIndexParser)->parse($this->fixture());

        // The fixture carries 17 widgets (ShareSansar's live page does not
        // show a standalone "Insurance" widget any more — only its Life /
        // Non Life split — even though the historical index picker still
        // lists it separately).
        $this->assertCount(17, $snapshot->rows);
    }

    #[Test]
    public function it_recovers_truncated_names_and_parses_their_values(): void
    {
        $snapshot = (new LiveIndexParser)->parse($this->fixture());
        $byName = collect($snapshot->rows)->keyBy(fn (IndexRow $row): string => $row->name);

        $nepse = $byName->get('NEPSE Index');
        $this->assertNotNull($nepse);
        $this->assertSame(2552.58, $nepse->value);
        $this->assertSame(-0.32, $nepse->changePercent);
        $this->assertSame(2567806881.0, $nepse->totalTurnover);
        $this->assertSame('nepse-index', $nepse->slug());

        // Truncated on the live page as "Development Bank Ind."
        $devBank = $byName->get('Development Bank Index');
        $this->assertNotNull($devBank);
        $this->assertSame(5334.91, $devBank->value);

        // Truncated as "Manufacturing And Pr."
        $manufacturing = $byName->get('Manufacturing And Processing');
        $this->assertNotNull($manufacturing);
        $this->assertSame(10641.78, $manufacturing->value);
        $this->assertSame(0.3, $manufacturing->changePercent);

        // Truncated as "Sensitive Float Inde."
        $sensitiveFloat = $byName->get('Sensitive Float Index');
        $this->assertNotNull($sensitiveFloat);
        $this->assertSame(152.71, $sensitiveFloat->value);
    }

    #[Test]
    public function the_widget_never_carries_an_absolute_change_amount_or_breadth_counts(): void
    {
        // ShareSansar's live widget publishes only a name, a turnover figure,
        // a value and a percent change — never an absolute change amount,
        // volume, transaction count, or advance/decline/unchanged breadth.
        $snapshot = (new LiveIndexParser)->parse($this->fixture());

        foreach ($snapshot->rows as $row) {
            $this->assertNull($row->changeAmount);
            $this->assertNull($row->totalVolume);
            $this->assertNull($row->totalTransactions);
            $this->assertNull($row->advances);
            $this->assertNull($row->declines);
            $this->assertNull($row->unchanged);
        }
    }

    #[Test]
    public function an_unparseable_page_yields_an_empty_snapshot_rather_than_guessing(): void
    {
        $snapshot = (new LiveIndexParser)->parse('<html><body>nothing here</body></html>');

        $this->assertSame([], $snapshot->rows);
        $this->assertNull($snapshot->tradeDate);
        $this->assertFalse($snapshot->dateIsAuthoritative);
    }
}
