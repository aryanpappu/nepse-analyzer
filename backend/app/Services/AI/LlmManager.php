<?php

declare(strict_types=1);

namespace App\Services\AI;

use InvalidArgumentException;

/**
 * Resolves model providers by name and caches one instance of each.
 *
 * The container binds LlmClient to whatever AI_DRIVER names, which covers the
 * normal case. This exists for the times you want a specific provider without
 * touching config — comparing the two on the same portfolio, or falling back to
 * the free provider when the paid one is out of credit.
 */
final class LlmManager
{
    public const DRIVERS = ['gemini', 'anthropic'];

    /** @var array<string, LlmClient> */
    private array $resolved = [];

    public function default(): LlmClient
    {
        return $this->driver((string) config('ai.driver'));
    }

    public function driver(string $name): LlmClient
    {
        return $this->resolved[$name] ??= match ($name) {
            'gemini' => new GeminiClient,
            'anthropic' => new ClaudeClient,
            default => throw new InvalidArgumentException(
                "Unknown AI driver [{$name}]. Supported drivers: ".implode(', ', self::DRIVERS).'.'
            ),
        };
    }

    /**
     * Whether the named driver has a key configured. Used by the health
     * endpoint so the UI can say which providers are actually usable.
     */
    public function isConfigured(string $name): bool
    {
        return match ($name) {
            'gemini' => trim((string) config('gemini.api_key')) !== '',
            'anthropic' => trim((string) config('anthropic.api_key')) !== '',
            default => false,
        };
    }

    /**
     * The model names the given driver will use, keyed by tier.
     *
     * @return array{default: string, deep: string}
     */
    public function models(string $name): array
    {
        $config = $name === 'gemini' ? 'gemini' : 'anthropic';

        return [
            'default' => (string) config($config.'.models.default'),
            'deep' => (string) config($config.'.models.deep'),
        ];
    }
}
