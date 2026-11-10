<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\StockPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class OperationalHealth
{
    public function __construct(private readonly Heartbeat $heartbeat) {}

    /** @return array<string, array{ok: bool, detail: string}> */
    public function checks(): array
    {
        $checks = [];
        try {
            DB::select('select 1');
            $checks['database'] = ['ok' => true, 'detail' => 'Database responds.'];
        } catch (Throwable) {
            $checks['database'] = ['ok' => false, 'detail' => 'Database is unavailable.'];
        }

        try {
            $probeKey = 'operations:probe:'.bin2hex(random_bytes(8));
            Cache::put($probeKey, 'ok', 15);
            $works = Cache::get($probeKey) === 'ok';
            Cache::forget($probeKey);
            $checks['cache'] = ['ok' => $works, 'detail' => $works ? 'Cache read/write succeeds.' : 'Cache read/write failed.'];
            foreach (['scheduler', 'pipeline', 'ai'] as $component) {
                $checks[$component] = $this->heartbeat->check($component);
            }
        } catch (Throwable) {
            $checks['cache'] = ['ok' => false, 'detail' => 'Cache is unavailable; heartbeats cannot be read.'];
            foreach (['scheduler', 'pipeline', 'ai'] as $component) {
                $checks[$component] = ['ok' => false, 'detail' => 'Heartbeat status unavailable.'];
            }
        }

        try {
            $latest = StockPrice::max('trade_date');
            $cutoff = Carbon::today()->subDays(max(1, (int) config('nepse.analysis.max_price_age_days', 7)));
            $fresh = $latest !== null && Carbon::parse($latest)->gte($cutoff) && Carbon::parse($latest)->lte(Carbon::today());
            $checks['market_data'] = ['ok' => $fresh, 'detail' => $fresh ? 'Recent market data exists.' : 'Market data is missing, old, or dated in the future. Check exchange closures and the ingestion pipeline.'];
        } catch (Throwable) {
            $checks['market_data'] = ['ok' => false, 'detail' => 'Market data could not be checked.'];
        }

        return $checks;
    }
}
