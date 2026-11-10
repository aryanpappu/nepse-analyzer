<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ClaudeApiException;
use App\Services\AI\ClaudeClient;
use App\Services\AI\GeminiClient;
use App\Services\AI\LlmClient;
use App\Services\AI\LlmManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Switching providers must be a config change and nothing more, so these tests
 * cover the seam itself: what the container hands the analysis services, and
 * whether a tier means the same thing on both sides of it.
 */
final class LlmManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('gemini.api_key', 'gemini-key');
        config()->set('gemini.models.default', 'gemini-fast');
        config()->set('gemini.models.deep', 'gemini-deep');
        config()->set('gemini.daily_call_limit', 0);
        config()->set('gemini.min_interval_ms', 0);
        config()->set('gemini.max_retries', 1);

        config()->set('anthropic.api_key', 'anthropic-key');
        config()->set('anthropic.models.default', 'claude-fast');
        config()->set('anthropic.models.deep', 'claude-deep');
        config()->set('anthropic.daily_call_limit', 0);
        config()->set('anthropic.max_retries', 1);
    }

    public function test_the_container_hands_over_the_driver_named_by_config(): void
    {
        config()->set('ai.driver', 'gemini');
        $this->assertInstanceOf(GeminiClient::class, $this->app->make(LlmClient::class));

        // A fresh container, because the binding is resolved once per instance.
        $this->refreshApplication();
        config()->set('ai.driver', 'anthropic');
        $this->assertInstanceOf(ClaudeClient::class, $this->app->make(LlmClient::class));
    }

    public function test_either_driver_can_be_reached_regardless_of_the_default(): void
    {
        config()->set('ai.driver', 'gemini');
        $manager = new LlmManager;

        $this->assertInstanceOf(GeminiClient::class, $manager->default());
        $this->assertInstanceOf(GeminiClient::class, $manager->driver('gemini'));
        // The paid provider stays reachable while the free one is the default,
        // so the two can be compared on identical input.
        $this->assertInstanceOf(ClaudeClient::class, $manager->driver('anthropic'));
    }

    public function test_drivers_are_reused_rather_than_rebuilt(): void
    {
        $manager = new LlmManager;

        // The Gemini driver paces requests using instance state; a fresh
        // instance per call would silently defeat it.
        $this->assertSame($manager->driver('gemini'), $manager->driver('gemini'));
    }

    public function test_an_unknown_driver_fails_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown AI driver \[oracle\]/');

        (new LlmManager)->driver('oracle');
    }

    public function test_it_reports_which_providers_have_a_key(): void
    {
        $manager = new LlmManager;

        $this->assertTrue($manager->isConfigured('gemini'));
        $this->assertTrue($manager->isConfigured('anthropic'));
        $this->assertFalse($manager->isConfigured('oracle'));

        config()->set('gemini.api_key', '   ');
        $this->assertFalse($manager->isConfigured('gemini'), 'whitespace is not a key');
    }

    public function test_it_reports_the_models_each_provider_will_use(): void
    {
        $manager = new LlmManager;

        $this->assertSame(['default' => 'gemini-fast', 'deep' => 'gemini-deep'], $manager->models('gemini'));
        $this->assertSame(['default' => 'claude-fast', 'deep' => 'claude-deep'], $manager->models('anthropic'));
    }

    public function test_the_deep_tier_selects_the_deep_model_on_both_drivers(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [
                    ['functionCall' => ['name' => 't', 'args' => ['ok' => true]]],
                ]]]],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ]),
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-deep',
                'stop_reason' => 'tool_use',
                'content' => [['type' => 'tool_use', 'name' => 't', 'input' => ['ok' => true]]],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ]),
        ]);

        $schema = ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]];

        (new GeminiClient)->structured('s', 'u', 't', 'd', $schema, LlmClient::TIER_DEEP);
        (new ClaudeClient)->structured('s', 'u', 't', 'd', $schema, LlmClient::TIER_DEEP);

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'gemini-deep:generateContent'));
        Http::assertSent(fn (Request $r): bool => ($r->data()['model'] ?? null) === 'claude-deep');
    }

    public function test_an_out_of_credit_response_names_the_cause_and_the_way_out(): void
    {
        config()->set('anthropic.max_retries', 3);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => ['type' => 'invalid_request_error', 'message' => 'Your credit balance is too low to access the Anthropic API.'],
        ], 400)]);

        try {
            (new ClaudeClient)->structured('s', 'u', 't', 'd', ['type' => 'object']);
            $this->fail('Expected a ClaudeApiException.');
        } catch (ClaudeApiException $e) {
            // The raw body says HTTP 400, which reads as a schema bug. It is not.
            $this->assertStringContainsString('no API credit', $e->getMessage());
            $this->assertStringContainsString('AI_DRIVER=gemini', $e->getMessage());
            // Paying for a retry loop against a billing error helps nobody.
            Http::assertSentCount(1);
        }
    }
}
