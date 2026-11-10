<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ProfileVerdict;
use App\Models\Stock;
use Illuminate\Support\Carbon;
use Tests\FeatureTestCase;

class PlaybookFreshnessTest extends FeatureTestCase
{
    public function test_stale_quote_is_excluded_from_public_buy_rankings(): void
    {
        $stock = Stock::factory()->create(['last_traded_on' => Carbon::today()->subDays(30)]);
        $this->verdict($stock, Carbon::today());
        $this->getJson('/api/playbook')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/stocks/'.$stock->symbol.'/playbook')->assertOk()
            ->assertJsonPath('data.profiles.0.source', 'unavailable')
            ->assertJsonPath('data.profiles.0.grade', null);
    }

    public function test_old_verdict_is_excluded_even_when_the_quote_is_fresh(): void
    {
        $stock = Stock::factory()->create();
        $this->verdict($stock, Carbon::today()->subDays(30));
        $this->getJson('/api/playbook')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_recent_quote_and_verdict_remain_visible(): void
    {
        $stock = Stock::factory()->create();
        $this->verdict($stock, Carbon::today());
        $this->getJson('/api/playbook')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_in_zone_filter_uses_the_current_quote_not_the_stored_flag(): void
    {
        $stock = Stock::factory()->price(150)->create();
        $this->verdict($stock, Carbon::today());
        $this->getJson('/api/playbook?in_zone=1')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/playbook')->assertOk()->assertJsonPath('data.0.price_in_zone', false);
    }

    private function verdict(Stock $stock, Carbon $date): void
    {
        ProfileVerdict::create([
            'stock_id' => $stock->id, 'investor_profile' => 'long_term',
            'analysis_date' => $date, 'grade' => 'buy', 'score' => 80,
            'reference_price' => 100, 'entry_low' => 95, 'entry_high' => 100,
            'stop_loss' => 90, 'target_1' => 110, 'target_2' => 120,
            'risk_reward' => 1, 'price_in_zone' => true,
            'scores' => [], 'reasons' => [], 'summary' => 'Fixture verdict',
        ]);
    }
}
