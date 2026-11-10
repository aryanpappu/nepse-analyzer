<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\InvestorProfile;
use App\Exceptions\ClaudeApiException;
use App\Jobs\GenerateBuyRecommendationsJob;
use App\Models\BuyRecommendationRun;
use App\Models\Portfolio;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\StockSignal;
use App\Models\User;
use App\Services\AI\BuyRecommendationService;
use App\Services\AI\LlmClient;
use App\Services\AI\Prompts\BuyRecommendationPrompt;
use App\Services\AI\Prompts\ScripComparisonPrompt;
use App\Services\AI\ScripComparisonAnalyzer;
use App\Services\Analysis\ScripComparator;
use App\Services\Portfolio\PortfolioValuationService;
use App\Services\Portfolio\TransactionCostCalculator;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Tests\FeatureTestCase;

class AnalysisSafetyTest extends FeatureTestCase
{
    public function test_market_refresh_requires_an_administrator(): void
    {
        $this->postJson('/api/market/refresh')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_USER]));
        $this->postJson('/api/market/refresh')->assertForbidden();
    }

    public function test_public_health_hides_operational_configuration(): void
    {
        $this->getJson('/api/health')->assertOk()
            ->assertJsonPath('data.ai', null)
            ->assertJsonPath('data.last_scrape', null)
            ->assertJsonPath('data.status.action', null)
            ->assertJsonPath('data.sources', []);
    }

    public function test_recommendations_only_accept_candidates_once_and_sanitize_trade_levels(): void
    {
        $candidate = Stock::factory()->price(100)->create();
        $other = Stock::factory()->price(100)->create();
        $portfolio = Portfolio::factory()->create();
        $result = [
            'data' => ['recommendations' => [
                ['symbol' => $other->symbol, 'suggested_quantity' => 10],
                ['symbol' => $candidate->symbol, 'suggested_quantity' => 17, 'stop_loss' => 110, 'target_price' => 90],
                ['symbol' => $candidate->symbol, 'suggested_quantity' => 10],
                'malformed row',
            ]],
            'model' => 'test', 'input_tokens' => 1, 'output_tokens' => 1, 'latency_ms' => 1,
        ];
        $run = (new ReflectionMethod(BuyRecommendationService::class, 'persist'))->invoke(
            $this->service(), $portfolio, Carbon::today(), 10000.0, $result, [$candidate->symbol],
        );

        $this->assertCount(1, $run->recommendations);
        $line = $run->recommendations->first();
        $this->assertSame($candidate->id, $line->stock_id);
        $this->assertEquals(10, $line->suggested_quantity);
        $fees = TransactionCostCalculator::fromConfig()->compute('buy', 10, 100, Carbon::today(), 'equity');
        $this->assertEquals($fees->turnover + $fees->total, $line->estimated_cost);
        $this->assertNull($line->stop_loss);
        $this->assertNull($line->target_price);
        $this->assertEquals(10000, $run->allocated_amount + $run->reserve_amount);
    }

    public function test_buy_fees_prevent_recommending_an_unaffordable_lot(): void
    {
        $stock = Stock::factory()->price(100)->create();
        $fees = TransactionCostCalculator::fromConfig()->compute('buy', 10, 100, Carbon::today(), 'equity');
        $cash = $fees->turnover + $fees->total - 0.01;
        $result = [
            'data' => ['recommendations' => [['symbol' => $stock->symbol, 'suggested_quantity' => 20]]],
            'model' => 'test', 'input_tokens' => 1, 'output_tokens' => 1, 'latency_ms' => 1,
        ];
        $run = (new ReflectionMethod(BuyRecommendationService::class, 'persist'))->invoke(
            $this->service(), Portfolio::factory()->create(), Carbon::today(), $cash, $result, [$stock->symbol],
        );
        $this->assertCount(0, $run->recommendations);
        $this->assertEquals($cash, $run->reserve_amount);
    }

    public function test_candidate_filter_rejects_stale_quotes_sell_signals_and_illiquidity(): void
    {
        $valid = $this->candidate();
        $stale = $this->candidate();
        $stale->update(['last_traded_on' => Carbon::today()->subDays(20)]);
        $sell = $this->candidate();
        $sell->signals()->update(['analysis_date' => Carbon::yesterday()]);
        StockSignal::create([
            'stock_id' => $sell->id, 'reference_price' => 100, 'analysis_date' => Carbon::today(), 'investor_profile' => 'long_term',
            'signal' => 'sell', 'confidence' => 90, 'risk_level' => 'high', 'time_horizon' => 'short_term', 'model' => 'test',
        ]);
        $illiquid = $this->candidate();
        $illiquid->prices()->update(['turnover' => 1]);

        $rows = (new ReflectionMethod(BuyRecommendationService::class, 'candidates'))->invoke($this->service(), 10000.0);
        $this->assertSame([$valid->symbol], array_column($rows, 'symbol'));
    }

    public function test_recommendations_reject_historical_dates_before_calling_the_model(): void
    {
        $this->expectException(ClaudeApiException::class);
        $this->expectExceptionMessage('historical requests are not supported');
        $this->service()->recommend(Portfolio::factory()->create(), Carbon::yesterday());
    }

    public function test_comparison_cache_reuses_unchanged_inputs_and_invalidates_changed_quotes(): void
    {
        $first = Stock::factory()->price(100)->create();
        $second = Stock::factory()->price(200)->create();
        $client = $this->createMock(LlmClient::class);
        $client->expects($this->exactly(2))->method('structured')->willReturn([
            'data' => ['winner' => $first->symbol, 'confidence' => 50, 'summary' => 'Test comparison'],
            'model' => 'test', 'input_tokens' => 1, 'output_tokens' => 1, 'latency_ms' => 1,
        ]);
        $analyzer = new ScripComparisonAnalyzer($client, new ScripComparisonPrompt, new ScripComparator);
        $symbols = [$first->symbol, $second->symbol];
        $original = $analyzer->analyze($symbols);
        $this->assertSame($original, $analyzer->analyze($symbols));
        $first->update(['last_price' => 120]);
        $changed = $analyzer->analyze($symbols);
        $this->assertNotSame($original['comparison'], $changed['comparison']);
    }

    public function test_candidates_use_only_the_requested_profiles_signal(): void
    {
        $stock = $this->candidate();
        StockSignal::create([
            'stock_id' => $stock->id, 'reference_price' => 100, 'analysis_date' => Carbon::today(),
            'investor_profile' => 'swing', 'signal' => 'sell', 'confidence' => 90,
            'risk_level' => 'high', 'time_horizon' => 'short_term', 'model' => 'test',
        ]);
        $method = new ReflectionMethod(BuyRecommendationService::class, 'candidates');
        $this->assertSame([$stock->symbol], array_column($method->invoke($this->service(), 10000.0, InvestorProfile::LongTerm), 'symbol'));
        $this->assertSame([], $method->invoke($this->service(), 10000.0, InvestorProfile::Swing));
    }

    public function test_recommendation_runs_are_independent_per_profile_and_legacy_runs_are_excluded(): void
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->forUser($user)->create();
        $stock = $this->candidate();
        $result = [
            'data' => ['recommendations' => [['symbol' => $stock->symbol, 'suggested_quantity' => 10, 'stop_loss' => 90, 'target_price' => 110]]],
            'model' => 'test', 'input_tokens' => 1, 'output_tokens' => 1, 'latency_ms' => 1,
        ];
        $method = new ReflectionMethod(BuyRecommendationService::class, 'persist');
        $long = $method->invoke($this->service(), $portfolio, Carbon::today(), 10000.0, $result, [$stock->symbol], InvestorProfile::LongTerm);
        $swing = $method->invoke($this->service(), $portfolio, Carbon::today(), 10000.0, $result, [$stock->symbol], InvestorProfile::Swing);
        $this->assertNotSame($long->id, $swing->id);
        $this->assertEquals(90, $long->recommendations->first()->stop_loss);
        $this->assertNull($swing->recommendations->first()->stop_loss);
        $this->assertSame(InvestorProfile::Swing->timeHorizon()->value, $swing->recommendations->first()->time_horizon);
        $this->assertCount(1, $long->fresh()->recommendations);
        BuyRecommendationRun::create([
            'portfolio_id' => $portfolio->id, 'analysis_date' => Carbon::tomorrow(),
            'investable_cash' => 10000, 'model' => 'legacy',
        ]);
        Sanctum::actingAs($user);
        $this->getJson('/api/portfolios/'.$portfolio->id.'/recommendations')->assertOk()
            ->assertJsonPath('data.investor_profile', 'long_term')->assertJsonPath('data.model', 'test');
        $this->getJson('/api/portfolios/'.$portfolio->id.'/recommendations?profile=swing')->assertOk()
            ->assertJsonPath('data.investor_profile', 'swing');
        $this->getJson('/api/portfolios/'.$portfolio->id.'/recommendations?profile=momentum')->assertOk()
            ->assertJsonPath('data', null);
        $this->getJson('/api/portfolios/'.$portfolio->id.'/recommendations?profile=invalid')->assertUnprocessable();
    }

    public function test_recommendation_job_uniqueness_includes_profile(): void
    {
        $long = new GenerateBuyRecommendationsJob(1, null, InvestorProfile::LongTerm);
        $swing = new GenerateBuyRecommendationsJob(1, null, InvestorProfile::Swing);
        $this->assertNotSame($long->uniqueId(), $swing->uniqueId());
    }

    private function candidate(): Stock
    {
        $stock = Stock::factory()->price(100)->create();
        StockPrice::factory()->create(['stock_id' => $stock->id, 'turnover' => 500000]);
        StockSignal::create([
            'stock_id' => $stock->id, 'reference_price' => 100, 'analysis_date' => Carbon::today(), 'investor_profile' => 'long_term',
            'signal' => 'buy', 'confidence' => 80, 'risk_level' => 'medium', 'time_horizon' => 'long_term', 'model' => 'test',
        ]);

        return $stock;
    }

    private function service(): BuyRecommendationService
    {
        return new BuyRecommendationService($this->createMock(LlmClient::class), new BuyRecommendationPrompt, new PortfolioValuationService);
    }
}
