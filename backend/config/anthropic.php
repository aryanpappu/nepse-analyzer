<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    */

    'api_key' => env('ANTHROPIC_API_KEY'),

    'base_url' => rtrim((string) env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'), '/'),

    'version' => env('ANTHROPIC_VERSION', '2023-06-01'),

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | "default" is used for the high volume per-stock signal analysis, "deep"
    | for the lower volume portfolio and fundamental analysis where the extra
    | reasoning quality is worth the cost.
    |
    */

    'models' => [
        'default' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
        'deep' => env('ANTHROPIC_MODEL_DEEP', 'claude-opus-5'),
    ],

    'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 4096),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    */

    'timeout' => (int) env('ANTHROPIC_TIMEOUT', 120),

    'connect_timeout' => (int) env('ANTHROPIC_CONNECT_TIMEOUT', 15),

    'max_retries' => (int) env('ANTHROPIC_MAX_RETRIES', 3),

    // Base delay in milliseconds for exponential backoff between retries.
    'retry_base_delay_ms' => (int) env('ANTHROPIC_RETRY_BASE_DELAY_MS', 1000),

    /*
    |--------------------------------------------------------------------------
    | Spend guard
    |--------------------------------------------------------------------------
    |
    | A full NEPSE analysis run touches every actively traded scrip. This is a
    | circuit breaker so a scheduling bug cannot bill an unbounded number of
    | requests. Counter lives in Redis and resets daily. Set to 0 to disable.
    |
    */

    'daily_call_limit' => (int) env('ANTHROPIC_DAILY_CALL_LIMIT', 500),

    /*
    |--------------------------------------------------------------------------
    | Analysis behaviour
    |--------------------------------------------------------------------------
    */

    // Reject a model signal whose confidence falls below this (0-100).
    'min_confidence' => (int) env('ANTHROPIC_MIN_CONFIDENCE', 0),

    // Sampling temperature. Low, because we want reproducible structured output.
    'temperature' => (float) env('ANTHROPIC_TEMPERATURE', 0.2),
];
