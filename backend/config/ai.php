<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Model provider
    |--------------------------------------------------------------------------
    |
    | Which LlmClient implementation the analysis services receive.
    |
    |   gemini     Google Gemini. Has a real free tier, so the whole pipeline
    |              can be exercised end to end before any money is spent.
    |   anthropic  Claude. Better financial reasoning; requires API credit.
    |
    | Nothing else in the codebase needs to change to switch — every analysis
    | service depends on the LlmClient interface, not on a concrete driver.
    |
    */

    'driver' => env('AI_DRIVER', 'gemini'),

];
