<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\ClaudeApiException;
use App\Services\AI\Concerns\GuardsDailySpend;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin adapter over the Anthropic Messages API.
 *
 * Two decisions worth calling out:
 *
 * 1. Structured output is obtained through *tool use*, not by asking for JSON
 *    in the prompt. The API validates the arguments against the schema, which
 *    removes the whole class of "model wrapped the JSON in prose" failures.
 *
 * 2. Every call passes through a Redis-backed daily counter. A scheduling bug
 *    that re-queues the full market would otherwise be an unbounded bill.
 */
final class ClaudeClient implements LlmClient
{
    use GuardsDailySpend;

    public function __construct() {}

    /**
     * Call the model and return the arguments it passed to the forced tool.
     *
     * @param  array<string, mixed>  $schema  JSON Schema for the tool input.
     * @return array{data: array<string, mixed>, model: string, input_tokens: int, output_tokens: int, latency_ms: int}
     *
     * @throws ClaudeApiException
     */
    public function structured(
        string $systemPrompt,
        string $userPrompt,
        string $toolName,
        string $toolDescription,
        array $schema,
        string $tier = self::TIER_DEFAULT,
        ?int $maxTokens = null,
    ): array {
        $model = (string) config('anthropic.models.'.$tier, config('anthropic.models.default'));

        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens ?? (int) config('anthropic.max_tokens'),
            'temperature' => (float) config('anthropic.temperature'),
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'tools' => [[
                'name' => $toolName,
                'description' => $toolDescription,
                'input_schema' => $schema,
            ]],
            // Forcing the tool guarantees a structured reply rather than prose.
            'tool_choice' => ['type' => 'tool', 'name' => $toolName],
        ];

        $startedAt = microtime(true);
        $response = $this->send($payload);
        $latencyMs = (int) ((microtime(true) - $startedAt) * 1000);

        $data = $this->extractToolInput($response, $toolName);

        return [
            'data' => $data,
            'model' => $response['model'] ?? $model,
            'input_tokens' => (int) ($response['usage']['input_tokens'] ?? 0),
            'output_tokens' => (int) ($response['usage']['output_tokens'] ?? 0),
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ClaudeApiException
     */
    private function send(array $payload): array
    {
        $apiKey = (string) config('anthropic.api_key');

        if (trim($apiKey) === '') {
            throw ClaudeApiException::missingApiKey();
        }

        $maxRetries = max(1, (int) config('anthropic.max_retries'));
        $baseDelay = (int) config('anthropic.retry_base_delay_ms');
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $this->reserveCall();

            try {
                $response = Http::withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => (string) config('anthropic.version'),
                    'content-type' => 'application/json',
                ])
                    ->timeout((int) config('anthropic.timeout'))
                    ->connectTimeout((int) config('anthropic.connect_timeout'))
                    ->post(config('anthropic.base_url').'/v1/messages', $payload);

                if ($response->successful()) {
                    return (array) $response->json();
                }

                $status = $response->status();

                // 429 and 5xx are worth retrying; 4xx client errors are not —
                // a bad schema will fail identically every time.
                if ($status !== 429 && $status < 500) {
                    throw ClaudeApiException::requestFailed($status, $response->body());
                }

                $lastError = ClaudeApiException::requestFailed($status, $response->body());

                Log::channel('analysis')->warning('Claude API call failed, retrying', [
                    'attempt' => $attempt,
                    'status' => $status,
                ]);
            } catch (ConnectionException $e) {
                $lastError = new ClaudeApiException('Connection to the Claude API failed: '.$e->getMessage(), [], $e);

                Log::channel('analysis')->warning('Claude API connection failed, retrying', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($attempt < $maxRetries) {
                // Exponential backoff: 1s, 2s, 4s ...
                usleep($baseDelay * (2 ** ($attempt - 1)) * 1000);
            }
        }

        throw $lastError ?? new ClaudeApiException('Claude API call failed for an unknown reason');
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     *
     * @throws ClaudeApiException
     */
    private function extractToolInput(array $response, string $toolName): array
    {
        foreach ((array) ($response['content'] ?? []) as $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === $toolName) {
                $input = $block['input'] ?? null;

                if (is_array($input)) {
                    return $input;
                }
            }
        }

        // A max_tokens stop means the tool arguments were cut off mid-object —
        // worth saying explicitly, because the fix is a bigger budget.
        if (($response['stop_reason'] ?? null) === 'max_tokens') {
            throw ClaudeApiException::unparseableResponse(
                'the response hit max_tokens before the tool call completed; increase ANTHROPIC_MAX_TOKENS'
            );
        }

        throw ClaudeApiException::unparseableResponse(
            "no tool_use block named '{$toolName}' in the response (stop_reason: "
            .($response['stop_reason'] ?? 'unknown').')'
        );
    }

    // ------------------------------------------------------------------
    // Spend guard — see GuardsDailySpend
    // ------------------------------------------------------------------

    protected function guardName(): string
    {
        return 'anthropic';
    }

    protected function dailyCallLimit(): int
    {
        return (int) config('anthropic.daily_call_limit');
    }
}
