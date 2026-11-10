<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\InvestorProfile;
use App\Http\Controllers\Concerns\AuthorizesPortfolioAccess;
use App\Http\Controllers\Controller;
use App\Models\Portfolio;
use App\Services\Portfolio\CapitalGainsTaxService;
use App\Services\Portfolio\IndexBenchmarkService;
use App\Services\Portfolio\PerformanceService;
use App\Services\Portfolio\PositionAdvice;
use App\Services\Portfolio\PositionAdvisor;
use App\Services\Portfolio\ReturnAttributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Position-level advice and year-by-year performance.
 *
 * Both are derived from the ledger and the stored verdicts — no AI call, so
 * these sit in the ordinary authenticated tier and can be polled freely.
 */
class PerformanceController extends Controller
{
    use AuthorizesPortfolioAccess;

    public function __construct(
        private readonly PositionAdvisor $advisor,
        private readonly PerformanceService $performance,
        private readonly IndexBenchmarkService $benchmark,
        private readonly ReturnAttributionService $attribution,
        private readonly CapitalGainsTaxService $capitalGains,
    ) {}

    /**
     * "What should I do today" for every open position, for one profile.
     */
    public function actions(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        $validated = $request->validate([
            'profile' => ['nullable', 'string', 'in:'.implode(',', InvestorProfile::values())],
        ]);

        $profile = InvestorProfile::tryFrom((string) ($validated['profile'] ?? ''))
            ?? InvestorProfile::default();

        $advice = $this->advisor->adviseAll($portfolio, $profile);

        // A count per action drives the summary chips above the list, so the UI
        // does not have to re-derive them from the rows.
        $counts = [];

        foreach ($advice as $item) {
            $counts[$item->action->value] = ($counts[$item->action->value] ?? 0) + 1;
        }

        return response()->json([
            'data' => array_map(static fn (PositionAdvice $a): array => $a->toArray(), $advice),
            'meta' => [
                'profile' => $profile->value,
                'profile_label' => $profile->label(),
                'hold_label' => $profile->holdLabel(),
                'max_position_weight_percent' => round($profile->maxPositionWeight() * 100, 1),
                'counts' => $counts,
                'needs_attention' => count(array_filter(
                    $advice,
                    static fn (PositionAdvice $a): bool => $a->action->urgency() >= 3,
                )),
            ],
        ]);
    }

    /**
     * Year-by-year profit and loss, plus lifetime figures.
     */
    public function performance(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        $validated = $request->validate([
            'basis' => ['nullable', 'string', 'in:'.PerformanceService::BASIS_FISCAL.','.PerformanceService::BASIS_CALENDAR],
            // Deposit rates differ by bank and move over time, so the benchmark
            // is re-basable per request rather than baked into config alone.
            'fd_rate' => ['nullable', 'numeric', 'min:0', 'max:30'],
        ]);

        /*
         * Precedence: an explicit ?fd_rate= wins (the page can re-base the
         * comparison live), then the user's saved rate, then the config
         * default. Without the middle step, saving a rate in profile settings
         * would appear to do nothing here.
         */
        $fdRate = isset($validated['fd_rate'])
            ? (float) $validated['fd_rate']
            : $request->user()?->benchmark_fd_rate;

        return response()->json([
            'data' => $this->performance->report(
                $portfolio,
                $validated['basis'] ?? PerformanceService::BASIS_FISCAL,
                $fdRate === null ? null : (float) $fdRate,
            ),
        ]);
    }

    /**
     * The market benchmark — "did stock-picking add anything", as against
     * /performance's deposit benchmark of "did this beat the bank".
     *
     * Separate from /performance rather than folded into it: the risk metrics
     * replay the portfolio at every sampled interval, which is materially more
     * work than the year buckets, and most page loads do not need it.
     *
     * Every section reports how much of the window the index actually covered.
     * A partial comparison is not a small error — with the index missing the
     * early part of a window it can invert the verdict outright, so
     * `index_simulation_complete` has to be shown, not assumed.
     */
    public function benchmark(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        $validated = $request->validate([
            'basis' => ['nullable', 'string', 'in:'.PerformanceService::BASIS_FISCAL.','.PerformanceService::BASIS_CALENDAR],
            // Compare against a sub-index instead of the whole market.
            'index' => ['nullable', 'string', 'max:80'],
            'interval' => ['nullable', 'string', 'in:daily,weekly,monthly'],
            'sections' => ['nullable', 'string'],
        ]);

        $slug = $validated['index'] ?? $request->user()?->benchmark_index_slug;
        $basis = $validated['basis'] ?? PerformanceService::BASIS_FISCAL;
        $interval = $validated['interval'] ?? 'weekly';

        $requested = $validated['sections'] ?? null;
        $wanted = $requested === null
            ? ['market', 'periods', 'risk', 'sectors']
            : array_values(array_intersect(
                array_map('trim', explode(',', $requested)),
                ['market', 'periods', 'risk', 'sectors'],
            ));

        /*
         * Cached, unlike /performance, because the risk section replays the
         * portfolio at every sampled interval — around 200 queries for a
         * two-year weekly window, on a 120/min tier.
         *
         * The key carries a version counter rather than using cache tags, so
         * this behaves the same on any cache store: a write to the portfolio
         * bumps the counter (see benchmarkVersion) and every stale key is
         * orphaned at once, left to expire on its own.
         */
        $key = sprintf(
            'portfolio:%d:benchmark:v%d:%s',
            $portfolio->id,
            self::benchmarkVersion($portfolio),
            md5(implode('|', [$slug ?? '-', $basis, $interval, implode(',', $wanted)])),
        );

        $data = Cache::remember(
            $key,
            (int) config('nepse.cache.benchmark', 1800),
            function () use ($portfolio, $slug, $basis, $interval, $wanted): array {
                $data = [];

                if (in_array('market', $wanted, true)) {
                    $data['market'] = $this->benchmark->marketWeightedComparison($portfolio, $slug);
                }

                if (in_array('periods', $wanted, true)) {
                    $data['periods'] = $this->benchmark->periodComparison($portfolio, $slug, $basis);
                }

                if (in_array('risk', $wanted, true)) {
                    $data['risk'] = $this->benchmark->riskMetrics($portfolio, $slug, $interval);
                }

                if (in_array('sectors', $wanted, true)) {
                    $data['sectors'] = $this->benchmark->sectorComparison($portfolio);
                }

                return $data;
            },
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Why the portfolio trails (or beats) the market.
     *
     * The benchmark gives a verdict; this decomposes it. Per-holding
     * contributions are constrained to sum to the total return — a tested
     * invariant, so a row that looks wrong is wrong rather than a rounding
     * artefact — and each sector is set against its own sub-index, which needs
     * no benchmark weights.
     *
     * `coverage.complete` false means a holding could not be priced at a
     * boundary and was excluded by name. Show that: a decomposition of 93% of
     * the capital is not a decomposition of the portfolio.
     */
    public function attribution(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : null;
        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : null;

        $key = sprintf(
            'portfolio:%d:attribution:v%d:%s',
            $portfolio->id,
            self::benchmarkVersion($portfolio),
            md5(($from?->toDateString() ?? '-').'|'.($to?->toDateString() ?? '-')),
        );

        $data = Cache::remember(
            $key,
            (int) config('nepse.cache.benchmark', 1800),
            fn (): array => $this->attribution->report($portfolio, $from, $to),
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Estimated capital gains tax on realised disposals.
     *
     * Nepal splits the rate on the holding period — 7.5% under 365 days, 5%
     * over — so the day count is the whole story, and `PerformanceService`
     * already matches acquisition dates FIFO, which is what a holding-period
     * rule needs.
     *
     * An estimate, not advice: tax is charged on winners per disposal, so
     * `taxable_gain` (winners only) and `gross_gain` (net of losses) are both
     * reported rather than picking one and misleading.
     */
    public function capitalGains(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        $validated = $request->validate([
            'investor_class' => ['nullable', 'string', 'in:individual,institution'],
            'basis' => ['nullable', 'string', 'in:'.PerformanceService::BASIS_FISCAL.','.PerformanceService::BASIS_CALENDAR],
        ]);

        $key = sprintf(
            'portfolio:%d:capital-gains:v%d:%s',
            $portfolio->id,
            self::benchmarkVersion($portfolio),
            md5(($validated['investor_class'] ?? '-').'|'.($validated['basis'] ?? '-')),
        );

        $data = Cache::remember(
            $key,
            (int) config('nepse.cache.benchmark', 1800),
            fn (): array => $this->capitalGains->report(
                $portfolio,
                $validated['investor_class'] ?? $request->user()?->investor_class,
                $validated['basis'] ?? PerformanceService::BASIS_FISCAL,
            ),
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Cache-busting counter for one portfolio's benchmark results.
     *
     * A trade, a cash correction or a ledger rebuild changes the answer, and
     * waiting out the TTL to see it would look like a bug. Callers that mutate
     * a portfolio call invalidateBenchmark().
     */
    /**
     * The same counter, for callers outside this controller whose answer also
     * moves when a trade or a cash correction lands.
     */
    public static function channelProfitVersion(Portfolio $portfolio): int
    {
        return self::benchmarkVersion($portfolio);
    }

    private static function benchmarkVersion(Portfolio $portfolio): int
    {
        return (int) Cache::get(self::benchmarkVersionKey($portfolio), 1);
    }

    public static function invalidateBenchmark(Portfolio $portfolio): void
    {
        $key = self::benchmarkVersionKey($portfolio);

        // add() seeds the counter only when it is absent, so a concurrent
        // increment cannot be lost to a racing initialisation.
        Cache::add($key, 1);
        Cache::increment($key);
    }

    private static function benchmarkVersionKey(Portfolio $portfolio): string
    {
        return "portfolio:{$portfolio->id}:benchmark:version";
    }
}
