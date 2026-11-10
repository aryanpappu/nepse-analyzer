<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStockRequest;
use App\Http\Resources\StockResource;
use App\Models\Sector;
use App\Models\Stock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class StockController extends Controller
{
    /** Columns a client may sort by, whitelisted to keep the query injection-proof. */
    private const SORTABLE = [
        'symbol' => 'symbol',
        'name' => 'name',
        'price' => 'last_price',
        'change' => 'change_percent',
        'market_cap' => 'market_capitalization',
        'traded_on' => 'last_traded_on',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'sector' => ['nullable', 'string', 'max:120'],
            'signal' => ['nullable', 'string', Rule::in(['buy', 'sell', 'hold'])],
            'instrument_type' => ['nullable', 'string', 'max:40'],
            'sort' => ['nullable', 'string', Rule::in(array_keys(self::SORTABLE))],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'min_change' => ['nullable', 'numeric'],
            'max_change' => ['nullable', 'numeric'],
        ]);

        $query = Stock::query()
            ->active()
            ->with(['sector', 'latestSignal', 'latestIndicator'])
            ->search($validated['search'] ?? null)
            ->inSector($validated['sector'] ?? null);

        if (isset($validated['instrument_type'])) {
            $query->where('instrument_type', $validated['instrument_type']);
        }

        if (isset($validated['signal'])) {
            $ttl = (int) config('nepse.analysis.signal_ttl_days');

            $query->whereHas('signals', function ($q) use ($validated, $ttl): void {
                $q->where('signal', $validated['signal'])
                    ->where('analysis_date', '>=', now()->subDays($ttl)->toDateString());
            });
        }

        if (isset($validated['min_change'])) {
            $query->where('change_percent', '>=', $validated['min_change']);
        }

        if (isset($validated['max_change'])) {
            $query->where('change_percent', '<=', $validated['max_change']);
        }

        $sortColumn = self::SORTABLE[$validated['sort'] ?? 'symbol'];
        $direction = $validated['direction'] ?? ($sortColumn === 'symbol' ? 'asc' : 'desc');

        // Nulls last regardless of engine, so unpriced scrips do not head the
        // list when sorting by price or market cap.
        $query->orderByRaw("({$sortColumn} IS NULL) asc")->orderBy($sortColumn, $direction);

        return StockResource::collection(
            $query->paginate($validated['per_page'] ?? 50)->withQueryString()
        );
    }

    public function show(Stock $stock): JsonResponse
    {
        $ttl = (int) config('nepse.cache.stock_detail');

        $payload = Cache::remember("stock:detail:{$stock->symbol}", $ttl, function () use ($stock): array {
            $stock->load(['sector', 'latestSignal', 'latestIndicator', 'latestFundamental', 'latestFundamentalAnalysis']);

            $indicator = $stock->latestIndicator;
            $fundamental = $stock->latestFundamental;
            $fundamentalAnalysis = $stock->latestFundamentalAnalysis;

            return [
                'stock' => (new StockResource($stock))->toArray(request()),
                'indicators' => $indicator === null ? null : [
                    'trade_date' => $indicator->trade_date->toDateString(),
                    'rsi_14' => $indicator->rsi_14,
                    'rsi_zone' => $indicator->rsiZone(),
                    'macd' => $indicator->macd,
                    'macd_signal' => $indicator->macd_signal,
                    'macd_histogram' => $indicator->macd_histogram,
                    'sma_20' => $indicator->sma_20,
                    'sma_50' => $indicator->sma_50,
                    'sma_200' => $indicator->sma_200,
                    'ema_12' => $indicator->ema_12,
                    'ema_26' => $indicator->ema_26,
                    'bollinger_upper' => $indicator->bollinger_upper,
                    'bollinger_middle' => $indicator->bollinger_middle,
                    'bollinger_lower' => $indicator->bollinger_lower,
                    'atr_14' => $indicator->atr_14,
                    'trend' => $indicator->trend,
                    'trend_strength' => $indicator->trend_strength,
                ],
                'fundamentals' => $fundamental === null ? null : [
                    'fiscal_period' => $fundamental->fiscal_period,
                    'eps' => $fundamental->eps,
                    'pe_ratio' => $fundamental->pe_ratio,
                    'book_value' => $fundamental->book_value,
                    'pb_ratio' => $fundamental->pb_ratio,
                    'dividend_percent' => $fundamental->dividend_percent,
                    'dividend_yield' => $fundamental->dividend_yield,
                    'roe' => $fundamental->roe,
                    'npl_percent' => $fundamental->npl_percent,
                    'capital_adequacy_ratio' => $fundamental->capital_adequacy_ratio,
                ],
                'fundamental_analysis' => $fundamentalAnalysis === null ? null : [
                    'analysis_date' => $fundamentalAnalysis->analysis_date->toDateString(),
                    'valuation' => $fundamentalAnalysis->valuation,
                    'quality_score' => $fundamentalAnalysis->quality_score,
                    'financial_health_score' => $fundamentalAnalysis->financial_health_score,
                    'dividend_sustainability_score' => $fundamentalAnalysis->dividend_sustainability_score,
                    'fair_value_low' => $fundamentalAnalysis->fair_value_low === null
                        ? null : (float) $fundamentalAnalysis->fair_value_low,
                    'fair_value_high' => $fundamentalAnalysis->fair_value_high === null
                        ? null : (float) $fundamentalAnalysis->fair_value_high,
                    'summary' => $fundamentalAnalysis->summary,
                    'strengths' => $fundamentalAnalysis->strengths,
                    'concerns' => $fundamentalAnalysis->concerns,
                ],
            ];
        });

        return response()->json(['data' => $payload]);
    }

    /**
     * OHLC history for the price chart.
     */
    public function history(Request $request, Stock $stock): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:5', 'max:'.config('nepse.history.max_days')],
            // Query strings carry booleans as the literal words "true"/"false",
            // which Laravel's `boolean` rule rejects. Accept both spellings and
            // let $request->boolean() normalise them below.
            'with_indicators' => ['nullable', 'in:0,1,true,false'],
        ]);

        $days = $validated['days'] ?? (int) config('nepse.history.default_days');

        $prices = $stock->prices()
            ->orderByDesc('trade_date')
            ->limit($days)
            ->get(['trade_date', 'open', 'high', 'low', 'close', 'volume', 'turnover'])
            ->sortBy('trade_date')
            ->values();

        $indicators = collect();

        if ($request->boolean('with_indicators')) {
            $indicators = $stock->indicators()
                ->orderByDesc('trade_date')
                ->limit($days)
                ->get(['trade_date', 'rsi_14', 'macd', 'macd_signal', 'macd_histogram',
                    'sma_20', 'sma_50', 'sma_200', 'bollinger_upper', 'bollinger_lower'])
                ->keyBy(fn ($row): string => $row->trade_date->toDateString());
        }

        $series = $prices->map(function ($price) use ($indicators): array {
            $date = $price->trade_date->toDateString();
            $indicator = $indicators->get($date);

            return array_filter([
                'date' => $date,
                'open' => $price->open === null ? null : (float) $price->open,
                'high' => $price->high === null ? null : (float) $price->high,
                'low' => $price->low === null ? null : (float) $price->low,
                'close' => (float) $price->close,
                'volume' => (int) $price->volume,
                'turnover' => (float) $price->turnover,
                'rsi_14' => $indicator?->rsi_14,
                'macd' => $indicator?->macd,
                'macd_signal' => $indicator?->macd_signal,
                'macd_histogram' => $indicator?->macd_histogram,
                'sma_20' => $indicator?->sma_20,
                'sma_50' => $indicator?->sma_50,
                'sma_200' => $indicator?->sma_200,
                'bollinger_upper' => $indicator?->bollinger_upper,
                'bollinger_lower' => $indicator?->bollinger_lower,
            ], static fn ($value): bool => $value !== null);
        });

        return response()->json([
            'data' => [
                'symbol' => $stock->symbol,
                'days' => $days,
                'count' => $series->count(),
                'series' => $series,
            ],
        ]);
    }

    /**
     * Manually add a scrip the crawler has not picked up.
     */
    public function store(StoreStockRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $stock = Stock::create([
            'symbol' => $validated['symbol'],
            'name' => $validated['name'],
            'sector_id' => Sector::resolve($validated['sector'] ?? null)?->id,
            'instrument_type' => $validated['instrument_type'] ?? 'equity',
            'listed_shares' => $validated['listed_shares'] ?? null,
            'paid_up_value' => $validated['paid_up_value'] ?? null,
            'market_capitalization' => $validated['market_capitalization'] ?? null,
            'last_price' => $validated['last_price'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json(
            ['data' => new StockResource($stock->load('sector'))],
            201,
        );
    }

    public function update(StoreStockRequest $request, Stock $stock): JsonResponse
    {
        $validated = $request->validated();

        if (array_key_exists('sector', $validated)) {
            $validated['sector_id'] = Sector::resolve($validated['sector'])?->id;
            unset($validated['sector']);
        }

        $stock->fill($validated)->save();

        Cache::forget("stock:detail:{$stock->symbol}");

        return response()->json(['data' => new StockResource($stock->fresh()->load('sector'))]);
    }

    public function destroy(Stock $stock): JsonResponse
    {
        // Soft retire rather than delete: price history and any portfolio
        // holdings referencing this scrip must survive.
        $stock->update(['is_active' => false, 'delisted_at' => now()]);

        Cache::forget("stock:detail:{$stock->symbol}");

        return response()->json(['message' => "{$stock->symbol} has been marked inactive."]);
    }
}
