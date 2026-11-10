<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Operations\OperationalHealth;
use App\Services\Operations\ReleasePreflight;
use Illuminate\Console\Command;

class ReleasePreflightCommand extends Command
{
    protected $signature = 'nepse:release-check
        {--configuration-only : Validate configuration without database/cache connections}
        {--json : Emit machine-readable results}';

    protected $description = 'Fail closed on incomplete public-release configuration and operational checks';

    public function handle(ReleasePreflight $preflight, OperationalHealth $health): int
    {
        $checks = $preflight->checks();
        if (! $this->option('configuration-only')) {
            $checks += $health->checks();
        }
        $ok = collect($checks)->every(static fn (array $check): bool => $check['ok']);
        if ($this->option('json')) {
            $this->line(json_encode(['ok' => $ok, 'checks' => $checks], JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Check', 'Status', 'Requirement'], collect($checks)->map(static fn (array $check, string $name): array => [$name, $check['ok'] ? 'PASS' : 'FAIL', $check['detail']])->all());
            $this->line('This checks configuration and process observations; it does not send mail or validate market-data rights.');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
