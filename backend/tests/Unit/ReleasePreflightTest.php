<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Operations\ReleasePreflight;
use Tests\TestCase;

class ReleasePreflightTest extends TestCase
{
    public function test_development_settings_and_placeholder_policies_fail_closed(): void
    {
        config(['app.env' => 'local', 'app.debug' => true, 'public_site.privacy_url' => 'https://example.com/privacy', 'public_site.market_data_permission_confirmed' => false]);
        $checks = (new ReleasePreflight)->checks();
        $this->assertFalse($checks['environment']['ok']);
        $this->assertFalse($checks['debug']['ok']);
        $this->assertFalse($checks['privacy_policy']['ok']);
        $this->assertFalse($checks['market_data_permission']['ok']);
    }

    public function test_configuration_only_command_never_connects_or_exposes_secrets(): void
    {
        config(['database.connections.mysql.password' => 'test-private-secret', 'app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->artisan('nepse:release-check', ['--configuration-only' => true, '--json' => true])
            ->doesntExpectOutputToContain('test-private-secret')->assertFailed();
    }

    public function test_ai_budget_cannot_be_disabled_for_a_configured_provider(): void
    {
        config(['ai.driver' => 'gemini', 'gemini.api_key' => 'test-key', 'gemini.daily_call_limit' => 0]);
        $this->assertFalse((new ReleasePreflight)->checks()['ai_budget']['ok']);
        config(['gemini.daily_call_limit' => 20]);
        $this->assertTrue((new ReleasePreflight)->checks()['ai_budget']['ok']);
    }
}
