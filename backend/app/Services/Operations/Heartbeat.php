<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class Heartbeat
{
    private const COMPONENTS = ['scheduler', 'pipeline', 'ai'];

    public function record(string $component): void
    {
        $this->validate($component);
        Cache::put('operations:heartbeat:'.$component, now()->timestamp, now()->addDay());
    }

    /** @return array{ok: bool, detail: string} */
    public function check(string $component): array
    {
        $this->validate($component);
        $last = Cache::get('operations:heartbeat:'.$component);
        $maxAge = max(60, (int) config('operations.'.$component.'_max_age_seconds'));
        $age = is_numeric($last) ? now()->timestamp - (int) $last : null;
        $ok = $age !== null && $age >= -60 && $age <= $maxAge;

        return [
            'ok' => $ok,
            'detail' => $age === null ? 'No heartbeat observed.' : ($ok ? 'Heartbeat is recent.' : 'Heartbeat is delayed; check the process and queue backlog.'),
        ];
    }

    private function validate(string $component): void
    {
        if (! in_array($component, self::COMPONENTS, true)) {
            throw new InvalidArgumentException('Unknown operational heartbeat component.');
        }
    }
}
