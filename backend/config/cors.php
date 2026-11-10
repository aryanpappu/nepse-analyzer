<?php

declare(strict_types=1);

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
     * Explicit origins rather than '*': the API issues bearer tokens, and a
     * wildcard origin would let any site on the internet call it with a token
     * it managed to obtain.
     */
    'allowed_origins' => array_values(array_filter([
        env('FRONTEND_URL', 'http://localhost:5173'),
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    // Token auth, not cookie auth — no credentials need to cross origins.
    'supports_credentials' => false,
];
