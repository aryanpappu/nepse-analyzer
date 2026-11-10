<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ScrapeDailyPricesJob;
use App\Models\ScrapeRun;
use App\Models\Sector;
use App\Models\StockPrice;
use App\Services\AI\LlmClient;
use App\Services\AI\LlmManager;
use App\Services\Operations\OperationalHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MarketController extends Controller
{
    /**
     * Dashboard header: breadth, turnover, top movers.
     */
    public function summary(): JsonResponse
    {
        $ttl = (int) config('nepse.cache.market_summary');

        $payload = Cache::remember('market:summary', $ttl, function (): array {
            $latestDate = StockPrice::max('trade_date');

            if ($latestDate === null) {
                return [
                    'trade_date' => null,
                    'message' => 'No price data yet. Run `php artisan nepse:scrape`.',
                    'advances' => 0,
                    'declines' => 0,
                    'unchanged' => 0,
                    'total_turnover' => 0.0,
                    'total_volume' => 0,
                    'traded_scrips' => 0,
                    'top_gainers' => [],
                    'top_losers' => [],
                    'most_active' => [],
                ];
            }

            $breadth = StockPrice::where('trade_date', $latestDate)
                ->selectRaw('
                    SUM(CASE WHEN change_percent > 0 THEN 1 ELSE 0 END) as advances,
                    SUM(CASE WHEN change_percent < 0 THEN 1 ELSE 0 END) as declines,
                    SUM(CASE WHEN change_percent = 0 OR change_percent IS NULL THEN 1 ELSE 0 END) as unchanged,
                    SUM(turnover) as total_turnover,
                    SUM(volume) as total_volume,
                    COUNT(*) as traded_scrips
                ')
                ->first();

            return [
                'trade_date' => $latestDate,
                'advances' => (int) $breadth->advances,
                'declines' => (int) $breadth->declines,
                'unchanged' => (int) $breadth->unchanged,
                'total_turnover' => (float) $breadth->total_turnover,
                'total_volume' => (int) $breadth->total_volume,
                'traded_scrips' => (int) $breadth->traded_scrips,
                'top_gainers' => $this->movers($latestDate, 'desc'),
                'top_losers' => $this->movers($latestDate, 'asc'),
                'most_active' => $this->mostActive($latestDate),
            ];
        });

        return response()->json(['data' => $payload]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function movers(string $date, string $direction): array
    {
        return StockPrice::query()
            ->join('stocks', 'stocks.id', '=', 'stock_prices.stock_id')
            ->where('stock_prices.trade_date', $date)
            ->whereNotNull('stock_prices.change_percent')
            // Ignore untraded scrips — a stale quote is not a "top gainer".
            ->where('stock_prices.volume', '>', 0)
            ->orderBy('stock_prices.change_percent', $direction)
            ->limit(10)
            ->get([
                'stocks.symbol', 'stocks.name',
                'stock_prices.close', 'stock_prices.change_percent',
                'stock_prices.volume', 'stock_prices.turnover',
            ])
            ->map(static fn ($row): array => [
                'symbol' => $row->symbol,
                'name' => $row->name,
                'close' => (float) $row->close,
                'change_percent' => (float) $row->change_percent,
                'volume' => (int) $row->volume,
                'turnover' => (float) $row->turnover,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mostActive(string $date): array
    {
        return StockPrice::query()
            ->join('stocks', 'stocks.id', '=', 'stock_prices.stock_id')
            ->where('stock_prices.trade_date', $date)
            ->orderByDesc('stock_prices.turnover')
            ->limit(10)
            ->get([
                'stocks.symbol', 'stocks.name',
                'stock_prices.close', 'stock_prices.change_percent',
                'stock_prices.volume', 'stock_prices.turnover',
            ])
            ->map(static fn ($row): array => [
                'symbol' => $row->symbol,
                'name' => $row->name,
                'close' => (float) $row->close,
                'change_percent' => $row->change_percent === null ? null : (float) $row->change_percent,
                'volume' => (int) $row->volume,
                'turnover' => (float) $row->turnover,
            ])
            ->all();
    }

    /**
     * Sector list with aggregate performance, for the filter bar and the
     * sector trend view.
     */
    public function sectors(): JsonResponse
    {
        $ttl = (int) config('nepse.cache.sectors');

        $payload = Cache::remember('market:sectors', $ttl, function (): array {
            $latestDate = StockPrice::max('trade_date');

            $sectors = Sector::query()
                ->withCount(['stocks' => fn ($q) => $q->where('is_active', true)])
                ->orderBy('name')
                ->get();

            if ($latestDate === null) {
                return $sectors->map(static fn (Sector $sector): array => [
                    'id' => $sector->id,
                    'name' => $sector->name,
                    'slug' => $sector->slug,
                    'stocks_count' => $sector->stocks_count,
                    'average_change_percent' => null,
                    'total_turnover' => null,
                    'advances' => null,
                    'declines' => null,
                ])->all();
            }

            // One grouped query for every sector, rather than N per-sector ones.
            $stats = DB::table('stock_prices')
                ->join('stocks', 'stocks.id', '=', 'stock_prices.stock_id')
                ->where('stock_prices.trade_date', $latestDate)
                ->whereNotNull('stocks.sector_id')
                ->groupBy('stocks.sector_id')
                ->select([
                    'stocks.sector_id',
                    DB::raw('AVG(stock_prices.change_percent) as avg_change'),
                    DB::raw('SUM(stock_prices.turnover) as total_turnover'),
                    DB::raw('SUM(CASE WHEN stock_prices.change_percent > 0 THEN 1 ELSE 0 END) as advances'),
                    DB::raw('SUM(CASE WHEN stock_prices.change_percent < 0 THEN 1 ELSE 0 END) as declines'),
                ])
                ->get()
                ->keyBy('sector_id');

            return $sectors->map(static function (Sector $sector) use ($stats): array {
                $row = $stats->get($sector->id);

                return [
                    'id' => $sector->id,
                    'name' => $sector->name,
                    'slug' => $sector->slug,
                    'stocks_count' => $sector->stocks_count,
                    'average_change_percent' => $row?->avg_change === null ? null : round((float) $row->avg_change, 2),
                    'total_turnover' => $row?->total_turnover === null ? null : (float) $row->total_turnover,
                    'advances' => $row === null ? null : (int) $row->advances,
                    'declines' => $row === null ? null : (int) $row->declines,
                ];
            })->all();
        });

        return response()->json(['data' => $payload]);
    }

    /**
     * Fetch prices right now, instead of waiting for the 12:00 / 15:00 run.
     *
     * Dispatched to the queue rather than run inline: a full crawl plus
     * indicator recalculation takes far longer than a browser will wait. The
     * job is unique-per-day, so double-clicking the button cannot start two
     * concurrent crawls against the source.
     */
    public function refresh(Request $request): JsonResponse
    {
        $lastRun = ScrapeRun::where('type', 'daily_prices')
            ->whereIn('status', [ScrapeRun::STATUS_SUCCESS, ScrapeRun::STATUS_PARTIAL])
            ->latest('id')
            ->first();

        // Be polite to a free public source: refuse a re-crawl within the
        // cooldown, and say when it will be available instead of silently
        // doing nothing.
        $cooldown = (int) config('nepse.refresh_cooldown_seconds', 300);

        if ($lastRun?->finished_at !== null) {
            // absolute, so a clock skew that puts finished_at slightly ahead of
            // now cannot produce a negative elapsed time and skip the cooldown.
            $elapsed = (int) $lastRun->finished_at->diffInSeconds(now(), absolute: true);

            if ($elapsed < $cooldown) {
                return response()->json([
                    'message' => sprintf(
                        'Prices were refreshed %d seconds ago. Try again in %d seconds.',
                        $elapsed,
                        $cooldown - $elapsed,
                    ),
                    'retry_after' => $cooldown - $elapsed,
                ], 429);
            }
        }

        ScrapeDailyPricesJob::dispatch(null, null, true);

        Cache::forget('market:summary');
        Cache::forget('market:sectors');

        return response()->json([
            'message' => 'Fetching the latest prices now. This takes about a minute — '
                .'the dashboard will show the new session once it finishes.',
        ], 202);
    }

    /**
     * Operational health — is the pipeline actually running?
     */
    public function health(Request $request, LlmClient $claude, LlmManager $llm): JsonResponse
    {
        $latestPriceDate = StockPrice::max('trade_date');
        $lastRun = ScrapeRun::where('type', 'daily_prices')->latest('id')->first();

        if (! $request->user('sanctum')?->isAdmin()) {
            $status = $this->freshness($latestPriceDate, $lastRun);
            $status['action'] = null;
            if ($status['level'] === 'error') {
                $status['detail'] = 'Market data is temporarily unavailable. Please check again later.';
            } elseif ($status['level'] === 'stale') {
                $status['detail'] = 'Market data has not updated recently. Check the quote date before using it.';
            } elseif ($status['level'] === 'empty') {
                $status['detail'] = 'Market data is being prepared. Please check again later.';
            }

            return response()->json(['data' => [
                'status' => $status,
                'latest_price_date' => $latestPriceDate,
                'last_scrape' => null,
                'ai' => null,
                'sources' => [],
            ]]);
        }

        $driver = (string) config('ai.driver');
        $models = $llm->models($driver);

        // Report every driver's readiness, not just the active one, so the UI can
        // tell "no key" apart from "key present but this provider is switched off".
        $available = [];
        foreach (LlmManager::DRIVERS as $name) {
            $available[$name] = [
                'configured' => $llm->isConfigured($name),
                'active' => $name === $driver,
            ];
        }

        return response()->json([
            'data' => [
                'operations' => app(OperationalHealth::class)->checks(),
                'status' => $this->freshness($latestPriceDate, $lastRun),
                'latest_price_date' => $latestPriceDate,
                'last_scrape' => $lastRun === null ? null : [
                    'status' => $lastRun->status,
                    'source' => $lastRun->source,
                    'trade_date' => $lastRun->trade_date?->toDateString(),
                    'rows_found' => $lastRun->rows_found,
                    'rows_rejected' => $lastRun->rows_rejected,
                    'error' => $lastRun->error,
                    'finished_at' => $lastRun->finished_at?->toIso8601String(),
                ],
                'ai' => [
                    'driver' => $driver,
                    'configured' => $llm->isConfigured($driver),
                    'model' => $models['default'],
                    'deep_model' => $models['deep'],
                    'calls_used_today' => $claude->callsUsedToday(),
                    'daily_call_limit' => (int) config($driver === 'gemini' ? 'gemini.daily_call_limit' : 'anthropic.daily_call_limit'),
                    'drivers' => $available,
                ],
                'sources' => config('nepse.sources'),
            ],
        ]);
    }

    /**
     * Is the data actually usable right now, in plain language?
     *
     * The raw fields below say what happened; they do not say whether anything
     * is wrong. A stale price table looks identical to a healthy one unless the
     * reader knows today's date and NEPSE's trading calendar — and the most
     * common failure on this project has been running `horizon` without
     * `schedule:work`, which leaves the queue busy, the UI normal, and prices
     * quietly frozen for days.
     *
     * So this returns a verdict, a reason and the command that fixes it.
     *
     * @return array<string, mixed>
     */
    private function freshness(mixed $latestPriceDate, ?ScrapeRun $lastRun): array
    {
        if ($latestPriceDate === null) {
            return [
                'level' => 'empty',
                'headline' => 'No price data yet',
                'detail' => 'Nothing has been crawled, so every page will be empty.',
                'action' => 'php artisan nepse:scrape',
            ];
        }

        $latest = Carbon::parse((string) $latestPriceDate)->startOfDay();
        $today = Carbon::now(config('nepse.timezone'))->startOfDay();
        $age = (int) $latest->diffInDays($today);

        if ($lastRun !== null && $lastRun->status === 'failed') {
            return [
                'level' => 'error',
                'headline' => 'The last price crawl failed',
                'detail' => $lastRun->error ?? 'The source could not be read.',
                'action' => 'php artisan nepse:scrape',
                'days_behind' => $age,
            ];
        }

        /*
         * NEPSE does not trade every day, so a one-day gap is normal and a
         * weekend is not a fault. Three days is the point at which a genuine
         * outage becomes more likely than a holiday — long enough not to cry
         * wolf over a Friday, short enough to catch a stopped scheduler before
         * a week of prices is missing.
         */
        if ($age >= 3) {
            return [
                'level' => 'stale',
                'headline' => sprintf('Prices are %d days old', $age),
                'detail' => 'The most common cause is the scheduler not running. Horizon only works the '
                    .'queue; a separate process dispatches the daily crawl.',
                'action' => 'php artisan schedule:work',
                'days_behind' => $age,
            ];
        }

        return [
            'level' => 'ok',
            'headline' => $age === 0 ? 'Prices are up to date' : sprintf('Prices are %d day(s) old', $age),
            'detail' => 'NEPSE does not trade every day, so a short gap is normal.',
            'action' => null,
            'days_behind' => $age,
        ];
    }
}
