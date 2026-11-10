<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class ClaudeApiException extends RuntimeException
{
    /** @var array<string, mixed> */
    public array $context = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message, array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->context = $context;
    }

    public static function missingApiKey(): self
    {
        return new self(
            'ANTHROPIC_API_KEY is not set. Add it to .env — AI analysis cannot run without it.',
        );
    }

    public static function missingGeminiKey(): self
    {
        return new self(
            'GEMINI_API_KEY is not set. Add it to .env — AI analysis cannot run without it.',
        );
    }

    public static function requestFailed(int $status, string $body): self
    {
        // The out-of-credit case arrives as a generic HTTP 400, so the raw body
        // is the only thing distinguishing "you owe money" from "bad schema".
        // Say so plainly rather than making the reader parse an API dump.
        if (str_contains($body, 'credit balance is too low')) {
            return new self(
                'The Anthropic account has no API credit, so analysis cannot run. '
                .'Add credit at console.anthropic.com (Plans & Billing), or set '
                .'AI_DRIVER=gemini in .env to use the free provider instead.',
                ['status' => $status],
            );
        }

        return new self(
            "Claude API returned HTTP {$status}: ".mb_substr($body, 0, 500),
            ['status' => $status],
        );
    }

    public static function geminiRequestFailed(int $status, string $body): self
    {
        if ($status === 429) {
            return new self(
                'Gemini rejected the call with HTTP 429 (quota exceeded). The free tier is '
                .'rate limited per minute and per day — wait and retry, or lower '
                .'GEMINI_DAILY_CALL_LIMIT. Detail: '.mb_substr($body, 0, 300),
                ['status' => $status],
            );
        }

        return new self(
            "Gemini API returned HTTP {$status}: ".mb_substr($body, 0, 500),
            ['status' => $status],
        );
    }

    public static function budgetExhausted(int $limit, string $driver = 'anthropic'): self
    {
        $setting = strtoupper($driver).'_DAILY_CALL_LIMIT';

        return new self(
            "Daily {$driver} API call limit of {$limit} reached; refusing further calls today. "
            ."Raise {$setting} if this is expected.",
            // Flagged in context rather than left to be recognised by its
            // wording — callers treat this as a hard stop, and matching on a
            // message is a silent break waiting for the next reword.
            ['limit' => $limit, 'driver' => $driver, 'budget_exhausted' => true],
        );
    }

    /**
     * Whether this is the daily spend guard refusing, rather than a call that
     * actually failed. A hard stop: retrying walks into the same wall.
     */
    public function isBudgetExhausted(): bool
    {
        return ($this->context['budget_exhausted'] ?? false) === true;
    }

    public static function unparseableResponse(string $detail): self
    {
        return new self('Could not read a structured result from the Claude response: '.$detail);
    }
}
