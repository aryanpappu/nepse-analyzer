<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\InvestorProfile;
use App\Exceptions\ClaudeApiException;
use App\Models\FundNav;
use App\Models\Stock;
use App\Services\AI\LlmClient;
use App\Services\AI\StockSignalAnalyzer;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\FeatureTestCase;

class StockSignalQualityTest extends FeatureTestCase
{
    public function test_fund_profiles_are_saved_independently(): void
    {
        $fund = Stock::factory()->mutualFund()->price(10)->create();
        FundNav::create(['stock_id' => $fund->id, 'nav_date' => Carbon::today(), 'nav' => 12, 'source' => 'test']);
        $client = Mockery::mock(LlmClient::class);
        $client->shouldReceive('structured')->twice()->andReturn([
            'data' => ['signal' => 'hold', 'confidence' => 60],
            'model' => 'test', 'input_tokens' => 1, 'output_tokens' => 1, 'latency_ms' => 1,
        ]);
        $this->app->instance(LlmClient::class, $client);
        $analyzer = $this->app->make(StockSignalAnalyzer::class);
        $profiles = InvestorProfile::cases();
        $first = $analyzer->analyze($fund, null, $profiles[0]);
        $second = $analyzer->analyze($fund, null, $profiles[1]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($profiles[0]->value, $first->getRawOriginal('investor_profile'));
        $this->assertSame($profiles[1]->value, $second->getRawOriginal('investor_profile'));
        $this->assertSame($profiles[1]->timeHorizon()->value, $second->getRawOriginal('time_horizon'));
    }

    public function test_fund_nav_must_be_recent_before_spending_on_ai(): void
    {
        $fund = Stock::factory()->mutualFund()->price(10)->create();
        FundNav::create(['stock_id' => $fund->id, 'nav_date' => Carbon::today()->subDays(90), 'nav' => 12, 'source' => 'test']);
        $client = Mockery::mock(LlmClient::class);
        $client->shouldNotReceive('structured');
        $this->app->instance(LlmClient::class, $client);
        $this->expectException(ClaudeApiException::class);
        $this->expectExceptionMessage('NAV data');
        $this->app->make(StockSignalAnalyzer::class)->analyze($fund);
    }

    public function test_stale_prices_are_rejected_before_spending_on_ai(): void
    {
        $stock = Stock::factory()->create(['last_traded_on' => Carbon::today()->subDays(30)]);
        $client = Mockery::mock(LlmClient::class);
        $client->shouldNotReceive('structured');
        $this->app->instance(LlmClient::class, $client);
        $this->expectException(ClaudeApiException::class);
        $this->expectExceptionMessage('stale or missing');
        $this->app->make(StockSignalAnalyzer::class)->analyze($stock);
    }

    public function test_historical_ai_signals_cannot_use_todays_metadata(): void
    {
        $stock = Stock::factory()->create();
        $client = Mockery::mock(LlmClient::class);
        $client->shouldNotReceive('structured');
        $this->app->instance(LlmClient::class, $client);
        $this->expectException(ClaudeApiException::class);
        $this->expectExceptionMessage('current date only');
        $this->app->make(StockSignalAnalyzer::class)->analyze($stock, Carbon::yesterday());
    }
}
