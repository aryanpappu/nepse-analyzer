<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ClaudeApiException;
use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Services\AI\ScripComparisonAnalyzer;
use App\Services\Analysis\FundamentalSnapshot;
use App\Services\Analysis\MetricCatalog;
use App\Services\Analysis\ScripComparator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ComparisonController extends Controller
{
    public function __construct(
        private readonly ScripComparator $comparator,
    ) {}

    /**
     * Head-to-head comparison: GET /api/compare?symbols=NABIL,EBL
     */
    public function compare(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbols' => ['required', 'string', 'max:120'],
        ]);

        $symbols = array_map('trim', explode(',', $validated['symbols']));

        try {
            $result = Cache::remember(
                'compare:'.implode('|', array_map('strtoupper', $symbols)),
                (int) config('nepse.cache.stock_detail', 300),
                fn (): array => $this->comparator->compare($symbols),
            );
        } catch (InvalidArgumentException $e) {
            // The comparator validates the *domain* rule (two to four known
            // symbols); surfacing it as a 422 keeps that rule in one place
            // rather than duplicating the symbol lookup in a FormRequest.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * Where one scrip sits in its sector: GET /api/stocks/{stock}/peers
     */
    public function peers(Stock $stock): JsonResponse
    {
        $stock->load(FundamentalSnapshot::relationsToLoad());

        $percentiles = Cache::remember(
            'peers:'.$stock->symbol,
            (int) config('nepse.cache.stock_detail', 300),
            fn (): array => $this->comparator->sectorPercentiles($stock),
        );

        $snapshot = FundamentalSnapshot::for($stock);
        $catalog = MetricCatalog::keyed();

        return response()->json([
            'data' => [
                'symbol' => $stock->symbol,
                'sector' => $stock->sector?->name,
                'fiscal_period' => $snapshot->fiscalPeriod,
                'data_coverage_percent' => $snapshot->coverage(),
                'metrics' => array_map(
                    static fn (string $key): array => [
                        'key' => $key,
                        'label' => $catalog[$key]->label,
                        'value' => $snapshot->get($key),
                        'display' => $catalog[$key]->format($snapshot->get($key)),
                        'percentile' => $percentiles[$key]['percentile'],
                        'sample' => $percentiles[$key]['sample'],
                        'better' => $percentiles[$key]['better'],
                    ],
                    array_keys($percentiles),
                ),
            ],
        ]);
    }

    /**
     * AI verdict on a head-to-head: POST /api/compare/analyze
     *
     * Separate from the free GET because this one spends an API call. The table
     * is identical either way; what this adds is the weighting judgement.
     */
    public function analyze(Request $request, ScripComparisonAnalyzer $analyzer): JsonResponse
    {
        $validated = $request->validate([
            'symbols' => ['required', 'array', 'min:2', 'max:4'],
            'symbols.*' => ['required', 'string', 'max:24'],
        ]);

        try {
            $result = $analyzer->analyze($validated['symbols']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ClaudeApiException $e) {
            Log::channel('analysis')->warning('Comparison AI request failed', ['error' => $e->getMessage()]);

            // Budget exhaustion and provider outages are expected operating
            // states, not bugs — the table is still available from GET /compare.
            return response()->json([
                'message' => 'AI analysis is currently unavailable. Please try again later.',
                'hint' => 'The computed comparison is still available from GET /api/compare.',
            ], 503);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * The metric vocabulary itself, so a client can render the table without
     * hardcoding labels and directions that would then drift from the backend.
     */
    public function metrics(): JsonResponse
    {
        return response()->json([
            'data' => array_map(static fn ($metric): array => [
                'key' => $metric->key,
                'label' => $metric->label,
                'unit' => $metric->unit->value,
                'better' => $metric->better->value,
                'rationale' => $metric->rationale,
                'scored' => $metric->isScored(),
            ], MetricCatalog::all()),
        ]);
    }
}
