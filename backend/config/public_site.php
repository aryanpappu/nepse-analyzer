<?php

declare(strict_types=1);

return [
    'operator_name' => env('PUBLIC_OPERATOR_NAME'),
    'contact_email' => env('PUBLIC_CONTACT_EMAIL'),
    'privacy_url' => env('PUBLIC_PRIVACY_URL'),
    'terms_url' => env('PUBLIC_TERMS_URL'),
    // Set only after the operator has obtained permission for the intended service.
    'market_data_permission_confirmed' => (bool) env('MARKET_DATA_PERMISSION_CONFIRMED', false),

    /*
     * Private instance: every market-data endpoint requires a signed-in user.
     * Use it when you have no permission to republish the scraped market data
     * to the public. Site information and health stay public so the login page
     * and uptime monitoring keep working.
     */
    'require_login' => (bool) env('SITE_REQUIRE_LOGIN', false),

    /*
     * Self-service sign-up. When false, POST /api/auth/register is refused and
     * only an administrator can create accounts (Admin → Users).
     */
    'registration_open' => (bool) env('REGISTRATION_OPEN', true),
];
