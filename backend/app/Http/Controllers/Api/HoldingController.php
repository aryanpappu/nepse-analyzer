<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\TransactionType;
use App\Http\Controllers\Concerns\AuthorizesPortfolioAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransactionRequest;
use App\Models\CashTransaction;
use App\Models\Portfolio;
use App\Models\PortfolioHolding;
use App\Models\Stock;
use App\Services\Portfolio\CashLedger;
use App\Services\Portfolio\HoldingCalculator;
use App\Services\Portfolio\PortfolioValuationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HoldingController extends Controller
{
    use AuthorizesPortfolioAccess;

    public function __construct(
        private readonly HoldingCalculator $calculator,
        private readonly PortfolioValuationService $valuation,
        private readonly CashLedger $cash,
    ) {}

    public function index(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        return response()->json([
            'data' => $this->valuation->holdingBreakdown($portfolio),
            'meta' => $this->valuation->summarize($portfolio),
        ]);
    }

    /**
     * Record a buy/sell/bonus/dividend and fold it into the position.
     */
    public function store(StoreTransactionRequest $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        $validated = $request->validated();
        $stock = Stock::symbol($validated['symbol'])->firstOrFail();
        $type = TransactionType::from($validated['type']);

        try {
            $transaction = DB::transaction(function () use ($portfolio, $stock, $validated, $type) {
                $transaction = $this->calculator->record($portfolio, $stock, $validated);

                // Optionally move the matching cash, so the account reconciles
                // without a second manual entry.
                if (($validated['settle_cash'] ?? false) === true) {
                    $impact = $transaction->netCashImpact();

                    if (abs($impact) >= 0.01) {
                        $this->cash->record(
                            $portfolio,
                            $validated['cash_account'] ?? 'bank',
                            $impact,
                            $impact < 0 ? 'trade_debit' : 'trade_credit',
                            null,
                            sprintf('%s %s %s', ucfirst($type->value), $validated['quantity'], $stock->symbol),
                            // Settled on the trade's own date and linked to it, so
                            // a backdated trade lands in the right place in the
                            // cash history and removing the trade removes its cash.
                            Carbon::parse($transaction->transacted_on),
                            portfolioTransactionId: (int) $transaction->id,
                        );
                    }
                }

                return $transaction;
            });
        } catch (RuntimeException $e) {
            // Business-rule violations (overselling, insufficient cash) are the
            // user's problem to fix, not a 500.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Cache::forget("portfolio:summary:{$portfolio->id}");
        // The benchmark is cached for far longer than the summary, so it needs
        // an explicit bump or a trade would not show up in it for half an hour.
        PerformanceController::invalidateBenchmark($portfolio);

        return response()->json([
            'message' => sprintf(
                '%s of %s %s recorded.',
                ucfirst($type->value),
                rtrim(rtrim(number_format((float) $validated['quantity'], 2, '.', ''), '0'), '.'),
                $stock->symbol,
            ),
            'data' => [
                'transaction_id' => $transaction->id,
                'holdings' => $this->valuation->holdingBreakdown($portfolio),
                'summary' => $this->valuation->summarize($portfolio->fresh()),
            ],
        ], 201);
    }

    /**
     * Edit a holding's notes.
     *
     * Quantity and average cost are not editable here: a position is a
     * function of the transaction ledger, and patching it directly produced a
     * number that the next rebuild silently threw away. A correction is a
     * transaction (or deleting a wrong one), never an overwrite.
     */
    public function update(Request $request, Portfolio $portfolio, PortfolioHolding $holding): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);
        $this->authorizeHolding($portfolio, $holding);

        if ($request->hasAny(['quantity', 'average_cost'])) {
            return response()->json([
                'message' => 'Quantity and average cost come from the transaction ledger. '
                    .'Record a buy, sell or bonus to change them, then rebuild if needed.',
                'errors' => [
                    'quantity' => ['Record a transaction instead of editing the position.'],
                ],
            ], 422);
        }

        $validated = $request->validate([
            'notes' => ['present', 'nullable', 'string', 'max:1000'],
        ]);

        $holding->update(['notes' => $validated['notes']]);

        return response()->json([
            'message' => 'Holding updated.',
            'data' => [
                'holdings' => $this->valuation->holdingBreakdown($portfolio),
                'summary' => $this->valuation->summarize($portfolio),
            ],
        ]);
    }

    public function destroy(Request $request, Portfolio $portfolio, PortfolioHolding $holding): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);
        $this->authorizeHolding($portfolio, $holding);

        $symbol = $holding->stock->symbol;

        try {
            DB::transaction(function () use ($portfolio, $holding, $symbol): void {
                $transactionIds = $portfolio->transactions()
                    ->where('stock_id', $holding->stock_id)
                    ->pluck('id');

                // Void the cash each trade settled, so the balance stops
                // holding money for trades that no longer exist. Voided rather
                // than deleted, so the history still explains the balance.
                $this->cash->voidMany(
                    $portfolio,
                    CashTransaction::where('portfolio_id', $portfolio->id)
                        ->whereIn('portfolio_transaction_id', $transactionIds)
                        ->notVoided()
                        ->get(),
                    "{$symbol} removed from the portfolio.",
                );

                // Remove the ledger entries too, otherwise a later rebuild would
                // resurrect the position.
                $portfolio->transactions()->whereIn('id', $transactionIds)->delete();
                $holding->delete();
            });
        } catch (RuntimeException $e) {
            // Reversing a sale's proceeds can overdraw the account once that
            // money has been spent; that is the user's to resolve, not a 500.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Cache::forget("portfolio:summary:{$portfolio->id}");
        // The benchmark is cached for far longer than the summary, so it needs
        // an explicit bump or a trade would not show up in it for half an hour.
        PerformanceController::invalidateBenchmark($portfolio);

        return response()->json([
            'message' => "{$symbol} removed from the portfolio.",
            'data' => [
                'holdings' => $this->valuation->holdingBreakdown($portfolio),
                'summary' => $this->valuation->summarize($portfolio),
            ],
        ]);
    }

    /**
     * Replay the transaction ledger and rewrite every position.
     */
    public function rebuild(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        $count = $this->calculator->rebuild($portfolio);

        Cache::forget("portfolio:summary:{$portfolio->id}");
        // The benchmark is cached for far longer than the summary, so it needs
        // an explicit bump or a trade would not show up in it for half an hour.
        PerformanceController::invalidateBenchmark($portfolio);

        return response()->json([
            'message' => "Rebuilt {$count} positions from the transaction history.",
            'data' => [
                'holdings' => $this->valuation->holdingBreakdown($portfolio),
                'summary' => $this->valuation->summarize($portfolio),
            ],
        ]);
    }

    private function authorizeHolding(Portfolio $portfolio, PortfolioHolding $holding): void
    {
        /*
         * Deliberately message-less — see the note in CashController: the
         * global api/* handler flattens this to "Not found.", and naming the
         * other portfolio would confirm the record exists there.
         */
        abort_unless($holding->portfolio_id === $portfolio->id, 404);
    }
}
