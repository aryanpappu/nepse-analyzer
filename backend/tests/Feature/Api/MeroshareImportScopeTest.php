<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Portfolio;
use App\Models\PortfolioImport;
use App\Models\PortfolioTransaction;
use App\Models\Stock;
use App\Models\User;
use App\Services\Import\PortfolioImportService;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * A MeroShare import replaces only the scrips it lists.
 *
 * "My Portfolio" is a snapshot of what is held *now*, so a scrip sold out
 * completely is simply absent from it. Reading that absence as "this never
 * existed" and deleting the whole ledger destroys the realised gains and the
 * capital-gains history for every position the export cannot see, including
 * fully disposed parcels that originally arrived through CDSC.
 *
 * The rule these tests hold: an export replaces what it has an opinion about
 * and stays silent about the rest. Silence must never mean deletion.
 */
final class MeroshareImportScopeTest extends FeatureTestCase
{
    private Portfolio $portfolio;

    private PortfolioImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->portfolio = Portfolio::factory()->forUser(User::factory()->create())->default()->create();
        $this->service = app(PortfolioImportService::class);
    }

    #[Test]
    public function it_keeps_a_scrip_the_export_never_mentions(): void
    {
        $listed = Stock::factory()->create(['symbol' => 'NABIL']);
        $soldOut = Stock::factory()->create(['symbol' => 'BHDC']);

        // A parcel the statement disposed of. MeroShare cannot show it, because
        // nothing is held any more — but its history is what the tax screen and
        // the realised-gain figures are built from.
        PortfolioTransaction::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'stock_id' => $soldOut->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 0,
        ]);

        // A stale position for a scrip the export *does* list, which the import
        // is entitled to replace.
        PortfolioTransaction::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'stock_id' => $listed->id,
            'type' => 'buy',
            'quantity' => 5,
            'price' => 100,
        ]);

        $this->service->commit($this->importListing('NABIL', 20, 300.50), true);

        $symbols = PortfolioTransaction::where('portfolio_id', $this->portfolio->id)
            ->join('stocks', 'stocks.id', '=', 'portfolio_transactions.stock_id')
            ->pluck('stocks.symbol')
            ->all();

        $this->assertContains('BHDC', $symbols, 'A scrip absent from the export must survive it.');
        $this->assertContains('NABIL', $symbols);

        // The listed scrip was replaced, not appended to.
        $nabil = PortfolioTransaction::where('portfolio_id', $this->portfolio->id)
            ->where('stock_id', $listed->id)
            ->get();

        $this->assertCount(1, $nabil);
        $this->assertEqualsWithDelta(20.0, (float) $nabil->first()->quantity, 0.001);
    }

    #[Test]
    public function a_surviving_scrip_keeps_its_position_after_the_rebuild(): void
    {
        $listed = Stock::factory()->create(['symbol' => 'NABIL']);
        $soldOut = Stock::factory()->create(['symbol' => 'HRL']);

        PortfolioTransaction::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'stock_id' => $soldOut->id,
            'type' => 'buy',
            'quantity' => 40,
            'price' => 250,
        ]);

        $this->service->commit($this->importListing('NABIL', 100, 500.0), true);

        // Holdings are dropped wholesale and replayed from the ledger, so a
        // preserved transaction must come back as a preserved position rather
        // than vanishing from the portfolio with its ledger still intact.
        $this->assertDatabaseHas('portfolio_holdings', [
            'portfolio_id' => $this->portfolio->id,
            'stock_id' => $soldOut->id,
        ]);
    }

    private function importListing(string $symbol, float $quantity, float $cost): PortfolioImport
    {
        return PortfolioImport::create([
            'portfolio_id' => $this->portfolio->id,
            'user_id' => $this->portfolio->user_id,
            'status' => PortfolioImport::STATUS_READY,
            'merged_rows' => [[
                'symbol' => $symbol,
                'quantity' => $quantity,
                'average_cost' => $cost,
                'include' => true,
                'status' => 'ready',
            ]],
        ]);
    }
}
