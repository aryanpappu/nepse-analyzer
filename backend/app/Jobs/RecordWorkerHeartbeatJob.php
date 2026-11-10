<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Operations\Heartbeat;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;

class RecordWorkerHeartbeatJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public int $uniqueFor = 90;

    public function __construct(public readonly string $component)
    {
        if (! in_array($component, ['pipeline', 'ai'], true)) {
            throw new InvalidArgumentException('Worker heartbeat requires a known queue.');
        }
        $this->onConnection($component === 'ai' ? 'redis-ai' : 'redis');
    }

    public function uniqueId(): string
    {
        return 'operations-heartbeat:'.$this->component;
    }

    public function handle(Heartbeat $heartbeat): void
    {
        $heartbeat->record($this->component);
    }
}
