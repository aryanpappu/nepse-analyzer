<?php

declare(strict_types=1);

return [
    'operator_name' => env('PUBLIC_OPERATOR_NAME'),
    'contact_email' => env('PUBLIC_CONTACT_EMAIL'),
    'privacy_url' => env('PUBLIC_PRIVACY_URL'),
    'terms_url' => env('PUBLIC_TERMS_URL'),
    // Set only after the operator has obtained permission for the intended service.
    'market_data_permission_confirmed' => (bool) env('MARKET_DATA_PERMISSION_CONFIRMED', false),
];
