<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Models\FundNav;
use App\Models\ScrapeRun;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\Scraper\Sources\SharesansarNavSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches and stores daily NAV for closed-end mutual funds, and pairs each NAV
 * with the market close on the same day so the premium/discount is stored
 * rather than recomputed at read time.
 */
final class FundNavService
{
    public function __construct(
        private readonly SharesansarNavSource $source,
    ) {}

    /**
     * @param  list<string>|null  $symbols  Null does every mutual fund.
     * @param  callable|null  $onProgress  fn(string $symbol, int $points): void
     */
    public function sync(?array $symbols = null, ?callable $onProgress = null): ScrapeRun
    {
        $run = ScrapeRun::create([
            'type' => 'fund_navs',
            'source' => $this->source->key(),
            'status' => ScrapeRun::STATUS_RUNNING,
            'started_at' => Carbon::now(),
        ]);

        $startedAt = microtime(true);

        $query = Stock::query()->active();

        if ($symbols !== null) {
            $query->whereIn('symbol', array_map('strtoupper', $symbols));
        } else {
            $query->where('instrument_type', 'mutual_fund');
        }

        $funds = $query->orderBy('symbol')->get();

        // One request resolves every symbol's internal id.
        $companyIds = $this->source->resolveCompanyIds();

        $created = 0;
        $updated = 0;
        $empty = 0;
        $failures = [];

        foreach ($funds as $fund) {
            $companyId = $fund->external_id ?? ($companyIds[$fund->symbol] ?? null);

            // Cache the id so later runs skip the lookup entirely.
            if ($companyId !== null && $fund->external_id === null) {
                $fund->update(['external_id' => $companyId]);
            }

            try {
                $series = $this->source->fetchNavHistory($fund->symbol, $companyId);
            } catch (Throwable $e) {
                Log::channel('scraper')->warning('NAV sync failed for fund', [
                    'symbol' => $fund->symbol,
                    'error' => $e->getMessage(),
                ]);

                if (count($failures) < 25) {
                    $failures[] = ['symbol' => $fund->symbol, 'reason' => $e->getMessage()];
                }

                continue;
            }

            if ($series === []) {
                $empty++;

                if ($onProgress !== null) {
                    $onProgress($fund->symbol, 0);
                }

                continue;
            }

            [$navCreated, $navUpdated] = $this->persist($fund, $series);
            $created += $navCreated;
            $updated += $navUpdated;

            if ($onProgress !== null) {
                $onProgress($fund->symbol, count($series));
            }
        }

        $run->update([
            'status' => $failures === [] ? ScrapeRun::STATUS_SUCCESS : ScrapeRun::STATUS_PARTIAL,
            'rows_found' => $funds->count(),
            'rows_created' => $created,
            'rows_updated' => $updated,
            'rows_rejected' => $empty,
            'rejections' => $failures,
            'finished_at' => Carbon::now(),
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        return $run->refresh();
    }

    /**
     * @param  list<array{date: string, nav: float}>  $series
     * @return array{0: int, 1: int}
     */
    private function persist(Stock $fund, array $series): array
    {
        $dates = array_column($series, 'date');

        // Market closes on the same dates, so the premium/discount is a like
        // for like comparison rather than NAV against a stale price.
        $closes = StockPrice::where('stock_id', $fund->id)
            ->whereIn('trade_date', $dates)
            ->pluck('close', 'trade_date')
            ->mapWithKeys(static fn ($close, $date): array => [
                Carbon::parse($date)->toDateString() => (float) $close,
            ]);

        $existing = FundNav::where('stock_id', $fund->id)
            ->whereIn('nav_date', $dates)
            ->pluck('nav_date')
            ->map(static fn ($date): string => Carbon::parse($date)->toDateString())
            ->flip();

        $rows = [];
        $now = Carbon::now();
        $created = 0;
        $updated = 0;

        foreach ($series as $point) {
            $marketPrice = $closes->get($point['date']);
            $nav = $point['nav'];

            $rows[] = [
                'stock_id' => $fund->id,
                'nav_date' => $point['date'],
                'nav' => $nav,
                'market_price' => $marketPrice,
                'premium_discount_percent' => $marketPrice === null || $nav <= 0
                    ? null
                    : round(($marketPrice - $nav) / $nav * 100, 4),
                'source' => $this->source->key(),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $existing->has($point['date']) ? $updated++ : $created++;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            FundNav::upsert(
                $chunk,
                ['stock_id', 'nav_date'],
                ['nav', 'market_price', 'premium_discount_percent', 'source', 'updated_at'],
            );
        }

        return [$created, $updated];
    }

    /**
     * Latest stored NAV for a fund.
     */
    public function latestNav(Stock $fund): ?FundNav
    {
        return FundNav::where('stock_id', $fund->id)->orderByDesc('nav_date')->first();
    }
}
