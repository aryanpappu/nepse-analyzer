<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\ClaudeApiException;

/**
 * The contract every model provider must satisfy.
 *
 * The analysis services depend on this rather than on a concrete provider, so
 * the pipeline can be exercised against a free provider (Gemini) before any
 * money is spent, then switched to Anthropic by changing one .env value.
 *
 * Structured output is always obtained through *forced tool use*, never by
 * asking for JSON in the prompt. Every provider behind this interface must
 * guarantee the model replies with tool arguments validated against the schema.
 */
interface LlmClient
{
    /** High volume, cost sensitive work — the per-stock signal sweep. */
    public const TIER_DEFAULT = 'default';

    /** Low volume, higher reasoning — portfolio, fundamental, buy lists. */
    public const TIER_DEEP = 'deep';

    /**
     * Call the model and return the arguments it passed to the forced tool.
     *
     * Callers choose a *tier*, never a model name. Each driver maps the tier
     * onto its own model, so no analysis service has to know whether it is
     * talking to Claude or Gemini.
     *
     * @param  array<string, mixed>  $schema  JSON Schema for the tool input.
     * @param  self::TIER_*  $tier
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
    ): array;

    /**
     * Calls used today against the daily spend guard — surfaced by the health
     * endpoint and the analyse command.
     */
    public function callsUsedToday(): int;
}
