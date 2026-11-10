<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Jobs\RecordWorkerHeartbeatJob;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\Operations\Heartbeat;
use App\Services\Operations\OperationalHealth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\FeatureTestCase;

class OperationalHealthTest extends FeatureTestCase
{
    public function test_scheduler_and_each_worker_are_checked_separately(): void
    {
        Cache::flush();
        $heartbeat = new Heartbeat;
        $heartbeat->record('scheduler');
        (new RecordWorkerHeartbeatJob('pipeline'))->handle($heartbeat);
        $this->assertTrue($heartbeat->check('scheduler')['ok']);
        $this->assertTrue($heartbeat->check('pipeline')['ok']);
        $this->assertFalse($heartbeat->check('ai')['ok']);
        $this->assertSame('redis-ai', (new RecordWorkerHeartbeatJob('ai'))->connection);
    }

    public function test_old_heartbeat_does_not_hide_a_stopped_scheduler(): void
    {
        Cache::put('operations:heartbeat:scheduler', now()->subMinutes(10)->timestamp, 3600);
        $this->assertFalse((new Heartbeat)->check('scheduler')['ok']);
    }

    public function test_health_command_succeeds_only_with_recent_heartbeats_and_quotes(): void
    {
        foreach (['scheduler', 'pipeline', 'ai'] as $component) {
            (new Heartbeat)->record($component);
        }
        StockPrice::factory()->for(Stock::factory())->on(Carbon::today())->create();
        $this->artisan('nepse:health', ['--json' => true])->assertSuccessful();
        Cache::forget('operations:heartbeat:scheduler');
        $this->artisan('nepse:health', ['--json' => true])->assertFailed();
    }

    public function test_public_health_never_exposes_process_diagnostics(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJsonMissingPath('data.operations');
        $this->assertFalse(app(OperationalHealth::class)->checks()['market_data']['ok']);
    }
}
