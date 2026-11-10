<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ClaudeApiException;
use App\Services\AI\GeminiClient;
use App\Services\AI\LlmClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * The Gemini driver is a translation layer, so these tests pin the translation
 * in both directions: the request shape Google expects, and the reading of a
 * reply whose field names differ from Anthropic's throughout.
 */
final class GeminiClientTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'health_score' => ['type' => 'integer'],
            'summary' => ['type' => 'string'],
        ],
        'required' => ['health_score'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('gemini.api_key', 'test-key');
        config()->set('gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');
        config()->set('gemini.models.default', 'model-fast');
        config()->set('gemini.models.deep', 'model-deep');
        config()->set('gemini.max_tokens', 4096);
        config()->set('gemini.temperature', 0.2);
        config()->set('gemini.thinking_level', 'low');
        config()->set('gemini.timeout', 30);
        config()->set('gemini.connect_timeout', 5);
        config()->set('gemini.max_retries', 3);
        config()->set('gemini.retry_base_delay_ms', 0);
        config()->set('gemini.daily_call_limit', 0);
        // Pacing would make every test sleep for seconds.
        config()->set('gemini.min_interval_ms', 0);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private static function reply(array $args, string $name = 'record_it', int $prompt = 100, int $out = 50, int $thoughts = 0): array
    {
        return [
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['functionCall' => ['name' => $name, 'args' => $args]]]],
            ]],
            'usageMetadata' => [
                'promptTokenCount' => $prompt,
                'candidatesTokenCount' => $out,
                'thoughtsTokenCount' => $thoughts,
            ],
        ];
    }

    private function invoke(string $tier = LlmClient::TIER_DEFAULT): array
    {
        return (new GeminiClient)->structured(
            'system text', 'user text', 'record_it', 'records it', self::SCHEMA, $tier,
        );
    }

    public function test_it_translates_the_request_into_geminis_dialect(): void
    {
        Http::fake(['*' => Http::response(self::reply(['health_score' => 7]))]);

        $this->invoke();

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            // System prompt moves out of the message list entirely.
            $this->assertSame('system text', $body['system_instruction']['parts'][0]['text']);
            $this->assertSame('user text', $body['contents'][0]['parts'][0]['text']);

            // The schema travels as function_declarations[].parameters.
            $declaration = $body['tools'][0]['function_declarations'][0];
            $this->assertSame('record_it', $declaration['name']);
            $this->assertSame(self::SCHEMA['properties'], $declaration['parameters']['properties']);

            // Mode ANY is what forces a tool call rather than prose.
            $this->assertSame('ANY', $body['tool_config']['function_calling_config']['mode']);
            $this->assertSame(['record_it'], $body['tool_config']['function_calling_config']['allowed_function_names']);

            $this->assertSame('low', $body['generationConfig']['thinkingConfig']['thinkingLevel']);
            $this->assertSame(4096, $body['generationConfig']['maxOutputTokens']);

            return true;
        });
    }

    public function test_it_sends_the_key_as_a_header_not_a_query_string(): void
    {
        Http::fake(['*' => Http::response(self::reply(['health_score' => 7]))]);

        $this->invoke();

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('test-key', $request->header('x-goog-api-key')[0]);
            // A key in the URL leaks into logs and proxies.
            $this->assertStringNotContainsString('test-key', $request->url());

            return true;
        });
    }

    public function test_the_tier_selects_the_model_so_callers_never_name_one(): void
    {
        Http::fake(['*' => Http::response(self::reply(['health_score' => 1]))]);

        $this->invoke(LlmClient::TIER_DEEP);

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'model-deep:generateContent'));
    }

    public function test_an_unknown_tier_falls_back_to_the_default_model(): void
    {
        Http::fake(['*' => Http::response(self::reply(['health_score' => 1]))]);

        $this->invoke('nonsense');

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'model-fast:generateContent'));
    }

    public function test_it_returns_the_function_arguments_and_maps_usage(): void
    {
        Http::fake(['*' => Http::response(self::reply(['health_score' => 62, 'summary' => 'ok'], 'record_it', 3042, 850, 120))]);

        $result = $this->invoke();

        $this->assertSame(['health_score' => 62, 'summary' => 'ok'], $result['data']);
        $this->assertSame('model-fast', $result['model']);
        $this->assertSame(3042, $result['input_tokens']);
        // Thinking tokens are billed as output, so they must be counted there.
        $this->assertSame(970, $result['output_tokens']);
        $this->assertIsInt($result['latency_ms']);
    }

    public function test_it_strips_schema_keywords_gemini_rejects(): void
    {
        Http::fake(['*' => Http::response(self::reply(['health_score' => 1]))]);

        (new GeminiClient)->structured('s', 'u', 'record_it', 'd', [
            'type' => 'object',
            'additionalProperties' => false,
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'properties' => [
                'nested' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => ['x' => ['type' => 'string', 'default' => 'no']],
                ],
            ],
        ]);

        Http::assertSent(function (Request $request): bool {
            $params = $request->data()['tools'][0]['function_declarations'][0]['parameters'];

            $this->assertArrayNotHasKey('additionalProperties', $params);
            $this->assertArrayNotHasKey('$schema', $params);
            // Stripping must reach nested schemas, not just the top level.
            $this->assertArrayNotHasKey('additionalProperties', $params['properties']['nested']);
            $this->assertArrayNotHasKey('default', $params['properties']['nested']['properties']['x']);
            // ...while leaving the parts Gemini does understand intact.
            $this->assertSame('string', $params['properties']['nested']['properties']['x']['type']);

            return true;
        });
    }

    public function test_it_preserves_enum_and_required_lists_through_sanitising(): void
    {
        Http::fake(['*' => Http::response(self::reply(['health_score' => 1]))]);

        (new GeminiClient)->structured('s', 'u', 'record_it', 'd', [
            'type' => 'object',
            'properties' => ['grade' => ['type' => 'string', 'enum' => ['A', 'B', 'C']]],
            'required' => ['grade'],
        ]);

        Http::assertSent(function (Request $request): bool {
            $params = $request->data()['tools'][0]['function_declarations'][0]['parameters'];

            $this->assertSame(['A', 'B', 'C'], $params['properties']['grade']['enum']);
            $this->assertSame(['grade'], $params['required']);

            return true;
        });
    }

    public function test_a_missing_key_fails_before_any_request_is_made(): void
    {
        config()->set('gemini.api_key', '');
        Http::fake();

        $this->expectException(ClaudeApiException::class);
        $this->expectExceptionMessageMatches('/GEMINI_API_KEY is not set/');

        try {
            $this->invoke();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_truncated_tool_call_explains_the_token_budget(): void
    {
        Http::fake(['*' => Http::response([
            'candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => [['text' => 'partial']]]]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 4096],
        ])]);

        $this->expectException(ClaudeApiException::class);
        $this->expectExceptionMessageMatches('/GEMINI_MAX_TOKENS or lower GEMINI_THINKING_LEVEL/');

        $this->invoke();
    }

    public function test_a_quota_rejection_says_what_to_do_about_it(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'quota']], 429)]);

        $this->expectException(ClaudeApiException::class);
        $this->expectExceptionMessageMatches('/free tier is rate limited/');

        $this->invoke();
    }

    public function test_deterministic_client_errors_are_not_retried(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'bad model']], 404)]);

        try {
            $this->invoke();
            $this->fail('Expected a ClaudeApiException.');
        } catch (ClaudeApiException) {
            // A 404 fails identically every time; retrying only wastes quota.
            Http::assertSentCount(1);
        }
    }

    public function test_transient_failures_are_retried_up_to_the_limit(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'boom']], 503)]);

        try {
            $this->invoke();
            $this->fail('Expected a ClaudeApiException.');
        } catch (ClaudeApiException) {
            Http::assertSentCount(3);
        }
    }

    public function test_it_recovers_when_a_retry_succeeds(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['message' => 'boom']], 503)
            ->push(self::reply(['health_score' => 44])),
        ]);

        $result = $this->invoke();

        $this->assertSame(44, $result['data']['health_score']);
        Http::assertSentCount(2);
    }

    public function test_the_daily_guard_refuses_once_the_limit_is_reached(): void
    {
        config()->set('gemini.daily_call_limit', 5);
        Redis::shouldReceive('connection->command')->once()->andReturn(0);
        Http::fake();

        $this->expectException(ClaudeApiException::class);
        $this->expectExceptionMessageMatches('/Daily .* call limit of 5 reached/');

        try {
            $this->invoke();
        } finally {
            // The whole point of the guard is to spend nothing once tripped.
            Http::assertNothingSent();
        }
    }

    public function test_the_daily_guard_allows_calls_below_the_limit(): void
    {
        config()->set('gemini.daily_call_limit', 5);
        Redis::shouldReceive('connection->command')->once()->andReturn(1);
        Http::fake(['*' => Http::response(self::reply(['health_score' => 3]))]);

        $this->assertSame(3, $this->invoke()['data']['health_score']);
    }

    public function test_a_redis_outage_pauses_requests_instead_of_disabling_the_budget(): void
    {
        config()->set('gemini.daily_call_limit', 5);
        Redis::shouldReceive('connection->command')->once()->andThrow(new \RuntimeException('redis is gone'));
        Http::fake();
        $this->expectException(ClaudeApiException::class);
        try {
            $this->invoke();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_retries_reserve_separate_slots_and_stop_at_the_limit(): void
    {
        config()->set('gemini.daily_call_limit', 1);
        Redis::shouldReceive('connection->command')->twice()->andReturn(1, 0);
        Http::fake(['*' => Http::response([], 500)]);
        $this->expectException(ClaudeApiException::class);
        $this->expectExceptionMessageMatches('/Daily .* call limit of 1 reached/');
        try {
            $this->invoke();
        } finally {
            Http::assertSentCount(1);
        }
    }
}
