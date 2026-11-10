<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\File;

final class ReleasePreflight
{
    /** @return array<string, array{ok: bool, detail: string}> */
    public function checks(): array
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $key = $decoded === false ? '' : $decoded;
        }
        $driver = (string) config('ai.driver');
        $provider = $driver === 'anthropic' ? 'anthropic' : 'gemini';
        $hasAiKey = trim((string) config($provider.'.api_key')) !== '';
        $checks = [
            'environment' => [(string) config('app.env') === 'production', 'APP_ENV must be production.'],
            'debug' => [config('app.debug') === false, 'APP_DEBUG must be false.'],
            'application_key' => [Encrypter::supported($key, (string) config('app.cipher')), 'Set a valid application encryption key.'],
            'application_url' => [$this->httpsUrl(config('app.url')), 'APP_URL must be a public HTTPS URL.'],
            'frontend_url' => [$this->httpsUrl(config('account.frontend_url')), 'FRONTEND_URL must be a public HTTPS URL for email links.'],
            'session_cookies' => [config('session.secure') === true, 'SESSION_SECURE_COOKIE must be true for operator sessions.'],
            'database_credentials' => [trim((string) config('database.connections.mysql.password')) !== '', 'Set a private database password.'],
            'redis_credentials' => [trim((string) config('database.redis.default.password')) !== '', 'Set a private Redis password.'],
            'shared_cache' => [config('cache.default') === 'redis', 'Use Redis as the shared production cache for budgets, throttles, and heartbeats.'],
            'queue' => [config('queue.default') === 'redis', 'Use Redis queues with supervised Horizon workers.'],
            'mail_transport' => [in_array(config('mail.default'), ['smtp', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun', 'sendmail'], true), 'Configure a real mail transport; log/array/fallback-to-log cannot recover accounts.'],
            'mail_sender' => [$this->email(config('mail.from.address')), 'Set a real MAIL_FROM_ADDRESS.'],
            'ai_budget' => [! $hasAiKey || (int) config($provider.'.daily_call_limit') > 0, 'A configured AI provider requires a positive daily attempt limit.'],
            'bootstrap_password' => [trim((string) config('bootstrap_admin.password')) === '', 'Remove ADMIN_PASSWORD after bootstrapping the administrator.'],
            'operator' => [trim((string) config('public_site.operator_name')) !== '', 'Set the public service operator name.'],
            'contact' => [$this->email(config('public_site.contact_email')), 'Set a working public contact email.'],
            'privacy_policy' => [$this->httpsUrl(config('public_site.privacy_url')), 'Configure the approved HTTPS privacy-policy URL.'],
            'terms' => [$this->httpsUrl(config('public_site.terms_url')), 'Configure the approved HTTPS terms URL.'],
            'market_data_permission' => [config('public_site.market_data_permission_confirmed') === true, 'Confirm redistribution permission for the intended market-data service.'],
            'source_license' => [File::exists(base_path('../LICENSE')) || File::exists(base_path('../LICENSE.md')), 'Choose and publish a repository source license.'],
        ];

        $results = [];
        foreach ($checks as $name => [$ok, $detail]) {
            $results[$name] = ['ok' => $ok, 'detail' => $detail];
        }

        return $results;
    }

    private function httpsUrl(mixed $url): bool
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return parse_url($url, PHP_URL_SCHEME) === 'https' && $host !== ''
            && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            && ! str_ends_with($host, '.example') && ! str_ends_with($host, '.invalid')
            && ! in_array($host, ['example.com', 'example.org', 'example.net'], true);
    }

    private function email(mixed $email): bool
    {
        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && ! preg_match('/@(example\.(com|org|net)|.*\.(example|invalid))$/i', $email);
    }
}
