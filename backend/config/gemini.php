<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    */

    'api_key' => env('GEMINI_API_KEY'),

    'base_url' => rtrim(
        (string) env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        '/'
    ),

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Mirrors the anthropic.php split: "default" for high volume per-stock
    | signals, "deep" for portfolio, fundamental and buy-recommendation work.
    |
    | The split is driven by the free tier's requests-per-day ceilings, which
    | differ by an order of magnitude:
    |
    |   flash-lite   500 RPD, 15 RPM  -> the only tier that can sweep the market
    |   flash         20 RPD,  5 RPM  -> fine for a handful of deep analyses
    |   pro            0 RPD,  0 RPM  -> no free quota at all; always HTTP 429
    |
    | Use a synthetic portfolio prompt to compare complete, schema-valid forced
    | function calls across tiers before choosing a more expensive model.
    |
    */

    'models' => [
        // 500/day — a full NEPSE signal sweep is only possible on this tier.
        'default' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
        // 20/day. Portfolio and fundamental analysis are low volume by design.
        'deep' => env('GEMINI_MODEL_DEEP', 'gemini-3.6-flash'),
    ],

    'max_tokens' => (int) env('GEMINI_MAX_TOKENS', 4096),

    /*
    |--------------------------------------------------------------------------
    | Thinking
    |--------------------------------------------------------------------------
    |
    | Gemini 3.x spends reasoning tokens from the same budget as the reply.
    | Left at its default, the portfolio prompt burned ~3.3k thinking tokens and
    | truncated the function call at 4096. "low" keeps the same prompt at zero
    | thinking tokens and a complete result. Set to an empty string to let the
    | model decide, but raise GEMINI_MAX_TOKENS to 8192+ if you do.
    |
    */

    'thinking_level' => env('GEMINI_THINKING_LEVEL', 'low'),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    */

    'timeout' => (int) env('GEMINI_TIMEOUT', 120),

    'connect_timeout' => (int) env('GEMINI_CONNECT_TIMEOUT', 15),

    'max_retries' => (int) env('GEMINI_MAX_RETRIES', 3),

    'retry_base_delay_ms' => (int) env('GEMINI_RETRY_BASE_DELAY_MS', 1000),

    /*
    |--------------------------------------------------------------------------
    | Spend guard
    |--------------------------------------------------------------------------
    |
    | The free tier is rate limited rather than billed, so this exists to stop a
    | runaway loop from burning the daily quota in minutes, not to cap a bill.
    | Counter lives in Redis and resets daily. Set to 0 to disable.
    |
    | Kept just under the 500/day flash-lite ceiling. Note this is one counter
    | across both tiers, while Google meters each model separately — the deep
    | tier's own 20/day cap is enforced by Google as a 429, not by this number.
    |
    */

    'daily_call_limit' => (int) env('GEMINI_DAILY_CALL_LIMIT', 450),

    /*
    |--------------------------------------------------------------------------
    | Request pacing
    |--------------------------------------------------------------------------
    |
    | Free tier requests-per-minute are low (15 for flash-lite, 5 for flash), and
    | a market sweep loops with no natural gap between calls. Without pacing the
    | run spends its retry budget on 429s. 4s ~= 15 RPM.
    |
    | Applies per process, so parallel queue workers still need worker-count
    | restraint. Set to 0 to disable.
    |
    */

    'min_interval_ms' => (int) env('GEMINI_MIN_INTERVAL_MS', 4000),

    /*
    |--------------------------------------------------------------------------
    | Analysis behaviour
    |--------------------------------------------------------------------------
    */

    'temperature' => (float) env('GEMINI_TEMPERATURE', 0.2),

];
