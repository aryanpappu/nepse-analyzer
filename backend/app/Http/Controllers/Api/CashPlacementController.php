<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPortfolioAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkStoreCashPlacementRequest;
use App\Http\Requests\StoreCashPlacementRequest;
use App\Http\Requests\UpdateCashPlacementRequest;
use App\Http\Requests\WithdrawCashPlacementRequest;
use App\Models\CashPlacement;
use App\Models\Portfolio;
use App\Services\Portfolio\CashPlacementService;
use App\Services\Portfolio\CashPlacementSettlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;

/**
 * Interest-bearing cash placements (bank fixed deposits, etc.) parked outside
 * the equity portfolio. No AI call and no market data, so this sits in the
 * ordinary authenticated tier alongside cash and holdings.
 */
final class CashPlacementController extends Controller
{
    use AuthorizesPortfolioAccess;

    public function __construct(
        private readonly CashPlacementService $placements,
        private readonly CashPlacementSettlement $settlement,
    ) {}

    /**
     * Every placement with interest accrued to date, plus maturity-awareness
     * totals: matured, maturing soon, total principal, total accrued
     * interest, and the weighted average rate of live capital.
     */
    public function index(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        $asOf = $this->resolveAsOf($request);
        $status = $request->query('status');

        $rows = $this->placements->forPortfolio($portfolio);

        if (is_string($status) && $status !== '') {
            $rows = $rows->filter(
                static fn (CashPlacement $p): bool => $p->status->value === $status
            )->values();
        }

        $settled = $this->settledMap($portfolio, $rows->all());

        $data = $rows->map(function (CashPlacement $p) use ($asOf, $settled): array {
            return $this->serialize($p, isset($settled[(int) $p->id])) + [
                'accrual' => $this->placements->accrualFor($p, $asOf)->toArray(),
            ];
        })->all();

        return response()->json([
            'data' => $data,
            'meta' => $this->placements->summarize($rows, $asOf),
        ]);
    }

    public function show(Request $request, Portfolio $portfolio, CashPlacement $cashPlacement): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);
        $this->authorizePlacement($portfolio, $cashPlacement);

        $asOf = $this->resolveAsOf($request);

        return response()->json([
            'data' => $this->serialize($cashPlacement, $this->isSettled($portfolio, $cashPlacement)) + [
                'accrual' => $this->placements->accrualFor($cashPlacement, $asOf)->toArray(),
            ],
        ]);
    }

    public function store(StoreCashPlacementRequest $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        /*
         * CashLedger::record() throws when a cash-settled placement would
         * overdraw the account. Unguarded that surfaced as a 500, while every
         * other money endpoint answers 422 with the reason — the rollback was
         * already correct, only the status code lied.
         */
        try {
            $placement = $this->placements->create($portfolio, $request->validated());
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Cache::forget("portfolio:summary:{$portfolio->id}");
        PerformanceController::invalidateBenchmark($portfolio);

        return response()->json([
            'message' => 'Cash placement recorded.',
            'data' => $this->serialize($placement, $this->isSettled($portfolio, $placement)),
        ], 201);
    }

    /**
     * Bulk create: all rows are validated together by
     * {@see BulkStoreCashPlacementRequest} before this method ever runs, and
     * the insert itself is wrapped in one transaction — so this is
     * all-or-nothing, never a partial batch.
     */
    public function bulkStore(BulkStoreCashPlacementRequest $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        /*
         * CashLedger::record() throws when a cash-settled placement would
         * overdraw the account. Unguarded that surfaced as a 500, while every
         * other money endpoint answers 422 with the reason — the rollback was
         * already correct, only the status code lied.
         */
        try {
            $rows = $this->placements->createMany($portfolio, $request->validated()['placements']);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Cache::forget("portfolio:summary:{$portfolio->id}");
        PerformanceController::invalidateBenchmark($portfolio);

        $bulkSettled = $this->settledMap($portfolio, $rows->all());

        return response()->json([
            'message' => "{$rows->count()} cash placements recorded.",
            'data' => $rows->map(fn (CashPlacement $p): array => $this->serialize($p, isset($bulkSettled[(int) $p->id])))->all(),
        ], 201);
    }

    public function update(
        UpdateCashPlacementRequest $request,
        Portfolio $portfolio,
        CashPlacement $cashPlacement,
    ): JsonResponse {
        $this->authorizeOwner($request, $portfolio);
        $this->authorizePlacement($portfolio, $cashPlacement);

        try {
            $placement = $this->placements->update($cashPlacement, $request->validated());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Cash placement updated.',
            'data' => $this->serialize($placement, $this->isSettled($portfolio, $placement)),
        ]);
    }

    public function destroy(Request $request, Portfolio $portfolio, CashPlacement $cashPlacement): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);
        $this->authorizePlacement($portfolio, $cashPlacement);

        $cashPlacement->delete();

        return response()->json(['message' => 'Cash placement deleted.']);
    }

    /**
     * Close a deposit — matured, or broken early.
     *
     * Separate from update() because this is not an edit: it stops accrual on
     * the closure date and, when the placement was cash-settled, credits the
     * principal (plus reinvested interest) back into the cash ledger. Doing
     * that through a generic PATCH would let a status change silently move
     * money.
     */
    public function withdraw(
        WithdrawCashPlacementRequest $request,
        Portfolio $portfolio,
        CashPlacement $cashPlacement,
    ): JsonResponse {
        $this->authorizeOwner($request, $portfolio);
        $this->authorizePlacement($portfolio, $cashPlacement);

        try {
            $placement = $this->placements->withdraw($cashPlacement, $request->validated());
        } catch (InvalidArgumentException|RuntimeException $e) {
            // An overdraw is refused by CashLedger, which rolls the closure back.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Cache::forget("portfolio:summary:{$portfolio->id}");
        PerformanceController::invalidateBenchmark($portfolio);

        return response()->json([
            'message' => 'Deposit closed.',
            'data' => $this->serialize($placement->fresh(), $this->isSettled($portfolio, $placement)),
        ]);
    }

    private function resolveAsOf(Request $request): Carbon
    {
        $asOf = $request->query('as_of');

        return is_string($asOf) && $asOf !== '' ? Carbon::parse($asOf) : Carbon::today();
    }

    /**
     * Route model binding resolves a placement by id alone, so it must be
     * confirmed to actually belong to the bound portfolio (nested route
     * segments are not otherwise scoped to each other).
     */
    private function authorizePlacement(Portfolio $portfolio, CashPlacement $cashPlacement): void
    {
        abort_unless($cashPlacement->portfolio_id === $portfolio->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(CashPlacement $p, bool $cashSettled = false): array
    {
        return [
            'id' => $p->id,
            'portfolio_id' => $p->portfolio_id,
            'kind' => $p->kind->value,
            'institution' => $p->institution,
            'reference' => $p->reference,
            'principal' => (float) $p->principal,
            'interest_rate' => (float) $p->interest_rate,
            'start_date' => $p->start_date->toDateString(),
            'maturity_date' => $p->maturity_date?->toDateString(),
            'closed_on' => $p->closed_on?->toDateString(),
            'compounding_basis' => $p->compounding_basis->value,
            'payout' => $p->payout->value,
            'status' => $p->status->value,
            'notes' => $p->notes,
            /*
             * Settlement is derived from the ledger, not stored on the row: a
             * placement counts toward net worth only if a cash transaction
             * actually debited an account for it. Exposed here so a client can
             * render "counts toward net worth" without cross-referencing the
             * portfolio summary. Resolved in one query per request by the
             * caller and passed in, never looked up per row.
             */
            'cash_settled' => $cashSettled,
        ];
    }

    private function isSettled(Portfolio $portfolio, CashPlacement $placement): bool
    {
        return isset($this->settledMap($portfolio, [$placement])[(int) $placement->id]);
    }

    /**
     * Which of these placements actually moved cash, in one query.
     *
     * @param  list<CashPlacement>  $placements
     * @return array<int, true>
     */
    private function settledMap(Portfolio $portfolio, array $placements): array
    {
        return $this->settlement->settledIds(
            $portfolio,
            array_map(static fn (CashPlacement $p): int => (int) $p->id, $placements),
        );
    }
}
