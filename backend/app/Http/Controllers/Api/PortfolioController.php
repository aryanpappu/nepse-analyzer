<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPortfolioAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePortfolioRequest;
use App\Http\Resources\PortfolioResource;
use App\Models\Portfolio;
use App\Services\Portfolio\CashLedger;
use App\Services\Portfolio\PortfolioValuationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class PortfolioController extends Controller
{
    use AuthorizesPortfolioAccess;

    public function __construct(
        private readonly PortfolioValuationService $valuation,
        private readonly CashLedger $cash,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $portfolios = $request->user()->portfolios()
            ->withCount('openHoldings')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return PortfolioResource::collection(
            $portfolios->map(fn (Portfolio $portfolio) => new PortfolioResource(
                $portfolio,
                $this->valuation->summarize($portfolio),
            ))
        );
    }

    public function show(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        return response()->json([
            'data' => [
                'portfolio' => (new PortfolioResource($portfolio, $this->valuation->summarize($portfolio)))
                    ->toArray($request),
                'holdings' => $this->valuation->holdingBreakdown($portfolio),
                'latest_analysis' => $this->analysisPayload($portfolio),
            ],
        ]);
    }

    public function store(StorePortfolioRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $portfolio = DB::transaction(function () use ($request, $validated): Portfolio {
            $isFirst = $request->user()->portfolios()->count() === 0;
            $makeDefault = $validated['is_default'] ?? $isFirst;

            if ($makeDefault) {
                // Only one default per user.
                $request->user()->portfolios()->update(['is_default' => false]);
            }

            return $request->user()->portfolios()->create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'currency' => $validated['currency'] ?? 'NPR',
                'cash_in_hand' => $validated['cash_in_hand'] ?? 0,
                'bank_balance' => $validated['bank_balance'] ?? 0,
                'is_default' => $makeDefault,
            ]);
        });

        return response()->json(
            ['data' => new PortfolioResource($portfolio, $this->valuation->summarize($portfolio))],
            201,
        );
    }

    public function update(StorePortfolioRequest $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        $validated = $request->validated();

        DB::transaction(function () use ($request, $portfolio, $validated): void {
            // Balances move through the ledger so the cash history stays
            // complete even when the owner types a corrected figure.
            foreach (['cash_in_hand' => 'cash', 'bank_balance' => 'bank'] as $field => $account) {
                if (array_key_exists($field, $validated)) {
                    $this->cash->setBalance($portfolio, $account, (float) $validated[$field]);
                    unset($validated[$field]);
                }
            }

            if (($validated['is_default'] ?? false) === true) {
                $request->user()->portfolios()->where('id', '!=', $portfolio->id)->update(['is_default' => false]);
            }

            $portfolio->fill($validated)->save();
        });

        return response()->json([
            'data' => new PortfolioResource($portfolio->fresh(), $this->valuation->summarize($portfolio->fresh())),
        ]);
    }

    public function destroy(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        $portfolio->delete();

        return response()->json(['message' => "Portfolio \"{$portfolio->name}\" deleted."]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function analysisPayload(Portfolio $portfolio): ?array
    {
        $analysis = $portfolio->latestAnalysis()->first();

        if ($analysis === null) {
            return null;
        }

        return [
            'analysis_date' => $analysis->analysis_date->toDateString(),
            'health_score' => $analysis->health_score,
            'diversification_grade' => $analysis->diversification_grade,
            'risk_level' => $analysis->risk_level,
            'summary' => $analysis->summary,
            'strengths' => $analysis->strengths,
            'weaknesses' => $analysis->weaknesses,
            'concentration_risks' => $analysis->concentration_risks,
            'rebalancing_actions' => $analysis->rebalancing_actions,
            'sector_allocation' => $analysis->sector_allocation,
            'model' => $analysis->model,
        ];
    }
}
