<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Operations\OperationalHealth;
use Illuminate\Console\Command;

class OperationalHealthCommand extends Command
{
    protected $signature = 'nepse:health {--json : Emit machine-readable results}';

    protected $description = 'Check database, cache, scheduler, workers, and market freshness without sending external requests';

    public function handle(OperationalHealth $health): int
    {
        $checks = $health->checks();
        $ok = collect($checks)->every(static fn (array $check): bool => $check['ok']);
        if ($this->option('json')) {
            $this->line(json_encode(['ok' => $ok, 'checks' => $checks], JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Check', 'Status', 'Detail'], collect($checks)->map(static fn (array $check, string $name): array => [$name, $check['ok'] ? 'PASS' : 'FAIL', $check['detail']])->all());
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
