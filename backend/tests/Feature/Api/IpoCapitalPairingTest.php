<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\CashTransaction;
use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use App\Models\Stock;
use App\Models\User;
use App\Services\Portfolio\IpoCapitalService;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * IPO subscription money is recorded as a pair, and nets to nothing.
 *
 * The money is paid from the applicant's own bank, so the broker account never
 * holds it. A lone debit therefore removed money that had never been there and
 * left the balance understated by the subscription total. In a fictional
 * example, 3,000.00 in subscriptions would reduce a 5,000.00 balance to
 * an incorrect 2,000.00.
 *
 * The pair says the true thing instead: money arrived from outside and turned
 * straight into shares. Balance unchanged, capital recorded.
 */
final class IpoCapitalPairingTest extends FeatureTestCase
{
    private Portfolio $portfolio;

    private IpoCapitalService $capital;

    private PortfolioTransaction $parcel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->portfolio = Portfolio::factory()->forUser(User::factory()->create())->default()->create();
        $this->capital = app(IpoCapitalService::class);

        $this->parcel = PortfolioTransaction::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'stock_id' => Stock::factory()->create(['symbol' => 'PHCL'])->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'acquisition_channel' => 'ipo',
        ]);
    }

    private function balance(): float
    {
        return round((float) CashTransaction::where('portfolio_id', $this->portfolio->id)
            ->whereNull('voided_at')
            ->sum('amount'), 2);
    }

    #[Test]
    public function recording_a_subscription_leaves_the_balance_unchanged(): void
    {
        $before = $this->balance();

        $this->capital->record($this->portfolio, $this->parcel);

        $this->assertEqualsWithDelta($before, $this->balance(), 0.001,
            'The subscription pair must net to zero — the broker account never held this money.');

        $this->assertDatabaseHas('cash_transactions', [
            'portfolio_id' => $this->portfolio->id,
            'type' => IpoCapitalService::FUNDING_TYPE,
            'amount' => 1000.00,
        ]);
        $this->assertDatabaseHas('cash_transactions', [
            'portfolio_id' => $this->portfolio->id,
            'type' => IpoCapitalService::TYPE,
            'amount' => -1000.00,
        ]);
    }

    #[Test]
    public function the_two_legs_are_tied_together(): void
    {
        $this->capital->record($this->portfolio, $this->parcel);

        $groups = CashTransaction::where('portfolio_id', $this->portfolio->id)
            ->whereIn('type', IpoCapitalService::syntheticTypes())
            ->pluck('transfer_group_id')
            ->unique();

        $this->assertCount(1, $groups, 'Both legs belong to one transfer group.');
        $this->assertNotNull($groups->first());
    }

    #[Test]
    public function unticking_removes_both_legs(): void
    {
        $before = $this->balance();

        $this->capital->record($this->portfolio, $this->parcel);
        $this->parcel->update(['acquisition_channel' => 'secondary']);
        $this->capital->reconcile($this->portfolio);

        $this->assertSame(0, CashTransaction::where('portfolio_id', $this->portfolio->id)
            ->whereIn('type', IpoCapitalService::syntheticTypes())
            ->count(), 'A leftover funding leg would credit the account for shares it never bought.');

        $this->assertEqualsWithDelta($before, $this->balance(), 0.001);
    }

    #[Test]
    public function reconcile_repairs_a_debit_written_before_the_pairing_existed(): void
    {
        // Exactly the shape of the eleven rows already in the real ledger: a
        // debit with no partner, written when only one leg was recorded.
        CashTransaction::create([
            'portfolio_id' => $this->portfolio->id,
            'type' => IpoCapitalService::TYPE,
            'account' => 'bank',
            'amount' => -1000.00,
            'reference' => IpoCapitalService::referenceFor((int) $this->parcel->id),
            'transacted_on' => $this->parcel->transacted_on,
        ]);

        $this->assertEqualsWithDelta(-1000.0, $this->balance(), 0.001);

        $result = $this->capital->reconcile($this->portfolio);

        $this->assertSame(1, $result['paired']);
        $this->assertEqualsWithDelta(0.0, $this->balance(), 0.001,
            'Repair must restore the balance the lone debit had understated.');
    }

    #[Test]
    public function repeated_reconciles_do_not_stack_up_legs(): void
    {
        $this->capital->reconcile($this->portfolio);
        $this->capital->reconcile($this->portfolio);
        $this->capital->reconcile($this->portfolio);

        $this->assertSame(1, CashTransaction::where('type', IpoCapitalService::FUNDING_TYPE)->count());
        $this->assertSame(1, CashTransaction::where('type', IpoCapitalService::TYPE)->count());
    }
}
