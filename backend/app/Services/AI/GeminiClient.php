<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\ClaudeApiException;
use App\Services\AI\Concerns\GuardsDailySpend;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Gemini driver, used to exercise the analysis pipeline for free.
 *
 * Gemini speaks a different dialect to Anthropic, so this adapter translates
 * in both directions:
 *
 *   system prompt          top-level "system" -> system_instruction.parts[]
 *   tool schema            tools[].input_schema -> function_declarations[].parameters
 *   forcing the tool       tool_choice -> tool_config.function_calling_config (mode ANY)
 *   reading the result     content[].tool_use.input -> parts[].functionCall.args
 *   usage                  usage.input_tokens -> usageMetadata.promptTokenCount
 *
 * One non-obvious detail: Gemini 3.x models spend "thinking" tokens out of the
 * same budget as the reply. A synthetic prompt describing 10 holdings can
 * still exhaust maxOutputTokens=4096 if thinking consumes the reply budget.
 * Pinning thinking_level low reserves more of that budget for the tool call;
 * the schema supplies the structure.
 */
final class GeminiClient implements LlmClient
{
    use GuardsDailySpend;

    /** Epoch milliseconds of the last request, for free tier RPM pacing. */
    private ?float $lastRequestAt = null;

    public function __construct() {}

    /**
     * @param  array<string, mixed>  $schema
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
        $model = (string) config('gemini.models.'.$tier, config('gemini.models.default'));

        $generationConfig = [
            'temperature' => (float) config('gemini.temperature'),
            'maxOutputTokens' => $maxTokens ?? (int) config('gemini.max_tokens'),
        ];

        if (($level = (string) config('gemini.thinking_level')) !== '') {
            $generationConfig['thinkingConfig'] = ['thinkingLevel' => $level];
        }

        $payload = [
            'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
            'tools' => [[
                'function_declarations' => [[
                    'name' => $toolName,
                    'description' => $toolDescription,
                    'parameters' => $this->sanitizeSchema($schema),
                ]],
            ]],
            // Mode ANY is Gemini's equivalent of Anthropic's forced tool_choice:
            // the model must answer by calling this function, never with prose.
            'tool_config' => [
                'function_calling_config' => [
                    'mode' => 'ANY',
                    'allowed_function_names' => [$toolName],
                ],
            ],
            'generationConfig' => $generationConfig,
        ];

        $startedAt = microtime(true);
        $response = $this->send($payload, $model);
        $latencyMs = (int) ((microtime(true) - $startedAt) * 1000);

        $usage = (array) ($response['usageMetadata'] ?? []);

        return [
            'data' => $this->extractFunctionArgs($response, $toolName),
            'model' => $model,
            'input_tokens' => (int) ($usage['promptTokenCount'] ?? 0),
            // Thinking tokens are billed as output, so count them to keep the
            // stored token figures honest against Google's own usage reporting.
            'output_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0)
                + (int) ($usage['thoughtsTokenCount'] ?? 0),
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ClaudeApiException
     */
    private function send(array $payload, string $model): array
    {
        $apiKey = (string) config('gemini.api_key');

        if (trim($apiKey) === '') {
            throw ClaudeApiException::missingGeminiKey();
        }

        $url = config('gemini.base_url').'/models/'.$model.':generateContent';

        $maxRetries = max(1, (int) config('gemini.max_retries'));
        $baseDelay = (int) config('gemini.retry_base_delay_ms');
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $this->reserveCall();

            try {
                $this->pace();

                $response = Http::withHeaders([
                    'x-goog-api-key' => $apiKey,
                    'content-type' => 'application/json',
                ])
                    ->timeout((int) config('gemini.timeout'))
                    ->connectTimeout((int) config('gemini.connect_timeout'))
                    ->post($url, $payload);

                if ($response->successful()) {
                    return (array) $response->json();
                }

                $status = $response->status();

                // 429 is the free tier's per-minute quota and is very much worth
                // waiting out; 5xx are transient. Other 4xx are deterministic.
                if ($status !== 429 && $status < 500) {
                    throw ClaudeApiException::geminiRequestFailed($status, $response->body());
                }

                $lastError = ClaudeApiException::geminiRequestFailed($status, $response->body());

                Log::channel('analysis')->warning('Gemini API call failed, retrying', [
                    'attempt' => $attempt,
                    'status' => $status,
                ]);
            } catch (ConnectionException $e) {
                $lastError = new ClaudeApiException(
                    'Connection to the Gemini API failed: '.$e->getMessage(), [], $e
                );

                Log::channel('analysis')->warning('Gemini API connection failed, retrying', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($attempt < $maxRetries) {
                // Exponential backoff: 1s, 2s, 4s ...
                usleep($baseDelay * (2 ** ($attempt - 1)) * 1000);
            }
        }

        throw $lastError ?? new ClaudeApiException('Gemini API call failed for an unknown reason');
    }

    /**
     * Hold requests apart so a tight analysis loop does not trip the free
     * tier's requests-per-minute limit and burn its retry budget on 429s.
     */
    private function pace(): void
    {
        $interval = (int) config('gemini.min_interval_ms');

        if ($interval <= 0) {
            return;
        }

        $now = microtime(true) * 1000;

        if ($this->lastRequestAt !== null) {
            $elapsed = $now - $this->lastRequestAt;

            if ($elapsed < $interval) {
                usleep((int) (($interval - $elapsed) * 1000));
            }
        }

        $this->lastRequestAt = microtime(true) * 1000;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     *
     * @throws ClaudeApiException
     */
    private function extractFunctionArgs(array $response, string $toolName): array
    {
        $candidate = (array) (($response['candidates'] ?? [])[0] ?? []);
        $parts = (array) (($candidate['content'] ?? [])['parts'] ?? []);

        foreach ($parts as $part) {
            $call = $part['functionCall'] ?? null;

            if (is_array($call) && ($call['name'] ?? null) === $toolName && is_array($call['args'] ?? null)) {
                return $call['args'];
            }
        }

        $finish = (string) ($candidate['finishReason'] ?? 'unknown');

        // Thinking tokens share the reply budget, so a truncated tool call almost
        // always means the budget was too small for the configured thinking level.
        if ($finish === 'MAX_TOKENS') {
            throw ClaudeApiException::unparseableResponse(
                'the response hit maxOutputTokens before the function call completed; '
                .'raise GEMINI_MAX_TOKENS or lower GEMINI_THINKING_LEVEL'
            );
        }

        throw ClaudeApiException::unparseableResponse(
            "no functionCall named '{$toolName}' in the Gemini response (finishReason: {$finish})"
        );
    }

    /**
     * Strip JSON Schema keywords Gemini's subset rejects.
     *
     * The prompt schemas are written against Anthropic's fuller JSON Schema
     * support; Gemini errors rather than ignoring what it does not know.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function sanitizeSchema(array $schema): array
    {
        $unsupported = [
            'additionalProperties', '$schema', '$id', '$ref', '$defs', 'definitions',
            'oneOf', 'allOf', 'not', 'const', 'examples', 'default',
            'patternProperties', 'exclusiveMinimum', 'exclusiveMaximum',
        ];

        $clean = [];

        foreach ($schema as $key => $value) {
            if (in_array($key, $unsupported, true)) {
                continue;
            }

            if ($key === 'properties' && is_array($value)) {
                $props = [];
                foreach ($value as $name => $definition) {
                    $props[$name] = is_array($definition) ? $this->sanitizeSchema($definition) : $definition;
                }
                $clean[$key] = $props;

                continue;
            }

            $clean[$key] = is_array($value) ? $this->sanitizeSchema($value) : $value;
        }

        return $clean;
    }

    protected function guardName(): string
    {
        return 'gemini';
    }

    protected function dailyCallLimit(): int
    {
        return (int) config('gemini.daily_call_limit');
    }
}
