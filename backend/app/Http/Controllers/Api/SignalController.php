<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\InvestorProfile;
use App\Http\Controllers\Controller;
use App\Http\Resources\StockSignalResource;
use App\Jobs\AnalyzeStockJob;
use App\Models\FundNav;
use App\Models\Stock;
use App\Models\StockSignal;
use App\Services\AI\LlmManager;
use App\Services\Scraper\InstrumentClassifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class SignalController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'signal' => ['nullable', 'string', Rule::in(['buy', 'sell', 'hold'])],
            'min_confidence' => ['nullable', 'integer', 'min:0', 'max:100'],
            'sector' => ['nullable', 'string', 'max:120'],
            'risk_level' => ['nullable', 'string', Rule::in(['low', 'medium', 'high'])],
            'time_horizon' => ['nullable', 'string', Rule::in(['short_term', 'medium_term', 'long_term'])],
            'include_stale' => ['nullable', 'in:0,1,true,false'],
            'profile' => ['nullable', 'string', 'in:'.implode(',', InvestorProfile::values())],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = StockSignal::query()
            ->with(['stock.sector'])
            ->ofType($validated['signal'] ?? null)
            ->minConfidence($validated['min_confidence'] ?? null);

        if (! $request->boolean('include_stale')) {
            $query->fresh();
        }

        if (isset($validated['risk_level'])) {
            $query->where('risk_level', $validated['risk_level']);
        }

        if (isset($validated['time_horizon'])) {
            $query->where('time_horizon', $validated['time_horizon']);
        }

        // A scrip can now carry a signal per profile for the same session, so
        // without this the list would show the same symbol up to three times.
        if (isset($validated['profile'])) {
            $query->where('investor_profile', $validated['profile']);
        }

        if (isset($validated['sector'])) {
            $sector = $validated['sector'];

            $query->whereHas('stock', function ($q) use ($sector): void {
                $q->whereHas('sector', fn ($s) => $s->where('slug', $sector)->orWhere('name', $sector));
            });
        }

        $query->orderByDesc('analysis_date')->orderByDesc('confidence');

        $signals = $query->paginate($validated['per_page'] ?? 25)->withQueryString();

        // An empty fresh list means one of two very different things: no analysis
        // has ever run, or it ran a while ago and everything has aged out. Those
        // need different actions from the user, so report which it is instead of
        // letting the UI guess. Only costs a count, and only when the list is
        // empty and the fresh filter is the likely reason.
        $staleAvailable = null;
        $newestAnalysisDate = null;

        if ($signals->total() === 0 && ! $request->boolean('include_stale')) {
            $staleAvailable = StockSignal::query()->count();
            $newestAnalysisDate = StockSignal::query()->max('analysis_date');
        }

        return StockSignalResource::collection($signals)->additional([
            'meta' => [
                'signal_ttl_days' => (int) config('nepse.analysis.signal_ttl_days', 3),
                'stale_available' => $staleAvailable,
                'newest_analysis_date' => $newestAnalysisDate === null
                    ? null
                    : Carbon::parse($newestAnalysisDate)->toDateString(),
            ],
        ]);
    }

    /**
     * Signal history for one stock.
     */
    public function forStock(Request $request, Stock $stock): AnonymousResourceCollection
    {
        $limit = (int) $request->integer('limit', 30);

        return StockSignalResource::collection(
            $stock->signals()
                ->with('stock.sector')
                ->orderByDesc('analysis_date')
                ->limit(max(1, min($limit, 200)))
                ->get()
        );
    }

    /**
     * Queue a fresh analysis for one stock, on demand.
     */
    public function analyze(Request $request, Stock $stock): JsonResponse
    {
        $validated = $request->validate([
            'profile' => ['nullable', 'string', 'in:'.implode(',', InvestorProfile::values())],
        ]);

        $profile = InvestorProfile::tryFrom((string) ($validated['profile'] ?? ''))
            ?? InvestorProfile::default();

        $driver = (string) config('ai.driver');

        // Gate on the provider in use rather than Anthropic specifically.
        if (! app(LlmManager::class)->isConfigured($driver)) {
            $key = $driver === 'gemini' ? 'GEMINI_API_KEY' : 'ANTHROPIC_API_KEY';

            return response()->json([
                'message' => "{$key} is not configured, so analysis cannot be queued.",
            ], 503);
        }

        $classifier = app(InstrumentClassifier::class);
        if (! $stock->is_active || ! $classifier->isAnalysable((string) $stock->instrument_type)) {
            return response()->json(['message' => 'This instrument is not supported for stock analysis.'], 422);
        }

        $cutoff = Carbon::today()->subDays(max(1, (int) config('nepse.analysis.max_price_age_days', 7)));
        if ((float) $stock->last_price <= 0 || $stock->getRawOriginal('last_traded_on') === null
            || Carbon::parse($stock->getRawOriginal('last_traded_on'))->lt($cutoff)) {
            return response()->json(['message' => 'Recent traded prices are required before analysis can be queued.'], 422);
        }

        if ($classifier->usesNav((string) $stock->instrument_type)) {
            $navCutoff = Carbon::today()->subDays(max(1, (int) config('nepse.analysis.max_nav_age_days', 45)));
            if (! FundNav::where('stock_id', $stock->id)->where('nav', '>', 0)
                ->whereBetween('nav_date', [$navCutoff->toDateString(), Carbon::today()->toDateString()])->exists()) {
                return response()->json(['message' => 'Recent NAV data is required before fund analysis can be queued.'], 422);
            }
        }

        $minHistory = (int) config('nepse.analysis.min_history_days', 30);

        if (! $classifier->usesNav((string) $stock->instrument_type)
            && $stock->prices()->whereDate('trade_date', '<=', Carbon::today()->toDateString())->count() < $minHistory) {
            return response()->json([
                'message' => "{$stock->symbol} does not have {$minHistory} sessions of price history yet.",
            ], 422);
        }

        AnalyzeStockJob::dispatch($stock->symbol, null, $profile->value);

        return response()->json([
            'message' => sprintf('Analysis queued for %s, as a %s.',
                $stock->symbol, strtolower($profile->label())),
            'profile' => $profile->value,
        ], 202);
    }
}
