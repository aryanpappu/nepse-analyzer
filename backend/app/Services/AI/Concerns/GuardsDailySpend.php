<?php

declare(strict_types=1);

namespace App\Services\AI\Concerns;

use App\Exceptions\ClaudeApiException;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Redis-backed daily call counter shared by every LlmClient driver.
 *
 * A full NEPSE run touches every actively traded scrip, so a scheduling bug
 * would otherwise be an unbounded bill. The counter is scoped per driver
 * because free and paid providers warrant very different ceilings.
 */
trait GuardsDailySpend
{
    /** Short driver name used in the Redis key, e.g. "anthropic" or "gemini". */
    abstract protected function guardName(): string;

    /** Calls allowed per day; 0 disables the guard. */
    abstract protected function dailyCallLimit(): int;

    private function budgetKey(): string
    {
        return 'ai:calls:'.$this->guardName().':'.Carbon::now()->toDateString();
    }

    /**
     * Reserve before sending, atomically across workers and HTTP requests.
     * Failed attempts still consume a reservation because provider billing may
     * occur before a connection is interrupted. Each retry needs its own slot.
     *
     * @throws ClaudeApiException
     */
    private function reserveCall(): void
    {
        $limit = $this->dailyCallLimit();
        if ($limit <= 0) {
            return;
        }

        $script = <<<'LUA'
        local used = tonumber(redis.call('GET', KEYS[1]) or '0')
        if used >= tonumber(ARGV[1]) then return 0 end
        local count = redis.call('INCR', KEYS[1])
        if count == 1 then redis.call('EXPIRE', KEYS[1], ARGV[2]) end
        return 1
        LUA;

        try {
            $connection = Redis::connection();
            // command() bypasses Laravel's eval adapter, so use each client's native argument shape.
            $arguments = $connection instanceof PhpRedisConnection
                ? [$script, [$this->budgetKey(), $limit, 129600], 1]
                : [$script, 1, $this->budgetKey(), $limit, 129600];
            $reserved = (int) $connection->command('eval', $arguments);
        } catch (Throwable $e) {
            Log::channel('analysis')->error('AI requests paused: spend guard unavailable', [
                'driver' => $this->guardName(),
                'error' => $e->getMessage(),
            ]);

            throw new ClaudeApiException('AI analysis is temporarily unavailable. Please try again later.', [], $e);
        }

        if ($reserved !== 1) {
            throw ClaudeApiException::budgetExhausted($limit, $this->guardName());
        }
    }

    public function callsUsedToday(): int
    {
        try {
            return (int) (Redis::get($this->budgetKey()) ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }
}
