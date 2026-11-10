<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPortfolioAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCashTransactionRequest;
use App\Http\Requests\UpdateCashTransactionRequest;
use App\Models\CashTransaction;
use App\Models\Portfolio;
use App\Services\Portfolio\CashLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class CashController extends Controller
{
    use AuthorizesPortfolioAccess;

    public function __construct(
        private readonly CashLedger $ledger,
    ) {}

    /**
     * Cash ledger history plus the current balances.
     */
    public function index(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        $query = $portfolio->cashTransactions()
            ->orderByDesc('transacted_on')
            ->orderByDesc('id');

        // Voided rows are excluded by default — an accidental 500k entry
        // should not read as permanent noise in the ledger — but stay
        // available on request so a user can still see what happened.
        if (! $request->boolean('include_voided')) {
            $query->notVoided();
        }

        $transactions = $query
            ->limit((int) $request->integer('limit', 100))
            ->get();

        return response()->json([
            'data' => [
                'cash_in_hand' => (float) $portfolio->cash_in_hand,
                'bank_balance' => (float) $portfolio->bank_balance,
                'investable_cash' => $portfolio->investableCash(),
                'transactions' => $transactions->map(static fn (CashTransaction $transaction): array => [
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'account' => $transaction->account,
                    'amount' => (float) $transaction->amount,
                    'balance_after' => $transaction->balance_after === null
                        ? null : (float) $transaction->balance_after,
                    'reference' => $transaction->reference,
                    'notes' => $transaction->notes,
                    'transacted_on' => $transaction->transacted_on->toDateString(),
                    'voided' => $transaction->isVoided(),
                    'void_reason' => $transaction->void_reason,
                    'corrected' => $transaction->isCorrected(),
                    // The UI shows what a row held before it was amended; without
                    // the timestamp it cannot say when, which is half the audit.
                    'corrected_at' => $transaction->corrected_at?->toIso8601String(),
                    'voided_at' => $transaction->voided_at?->toIso8601String(),
                    'original_values' => $transaction->original_values,
                ])->all(),
            ],
        ]);
    }

    /**
     * Deposit, withdraw, transfer between accounts, or set a balance outright.
     */
    public function store(StoreCashTransactionRequest $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        $validated = $request->validated();
        $amount = (float) $validated['amount'];
        $date = isset($validated['transacted_on']) ? Carbon::parse($validated['transacted_on']) : null;

        try {
            $result = match ($validated['action']) {
                'deposit' => $this->ledger->deposit(
                    $portfolio,
                    $validated['account'],
                    $amount,
                    $validated['reference'] ?? null,
                    $validated['notes'] ?? null,
                    $date,
                ),
                'withdraw' => $this->ledger->withdraw(
                    $portfolio,
                    $validated['account'],
                    $amount,
                    $validated['reference'] ?? null,
                    $validated['notes'] ?? null,
                    $date,
                ),
                'transfer' => $this->ledger->transfer(
                    $portfolio,
                    $validated['from_account'],
                    $validated['to_account'],
                    $amount,
                    $date,
                ),
                'set' => $this->ledger->setBalance(
                    $portfolio,
                    $validated['account'],
                    $amount,
                    $validated['notes'] ?? null,
                    $date,
                ),
            };
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Cache::forget("portfolio:summary:{$portfolio->id}");
        // The benchmark is cached for far longer than the summary, so it needs
        // an explicit bump or a trade would not show up in it for half an hour.
        PerformanceController::invalidateBenchmark($portfolio);

        $portfolio->refresh();

        return response()->json([
            'message' => $result === null
                ? 'Balance already matched that amount; nothing to record.'
                : 'Cash movement recorded.',
            'data' => [
                'cash_in_hand' => (float) $portfolio->cash_in_hand,
                'bank_balance' => (float) $portfolio->bank_balance,
                'investable_cash' => $portfolio->investableCash(),
            ],
        ], 201);
    }

    /**
     * Amend a mis-entered transaction's amount, date, account, reference or
     * notes in place, rather than offsetting it with a second correcting
     * entry. Every later `balance_after` for the affected account(s) is
     * recomputed from the ledger, not patched.
     */
    public function update(
        UpdateCashTransactionRequest $request,
        Portfolio $portfolio,
        CashTransaction $transaction,
    ): JsonResponse {
        $this->authorizeOwner($request, $portfolio);
        $this->authorizeTransaction($portfolio, $transaction);

        try {
            $this->ledger->amend($portfolio, $transaction, $request->validated());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Cache::forget("portfolio:summary:{$portfolio->id}");
        // The benchmark is cached for far longer than the summary, so it needs
        // an explicit bump or a trade would not show up in it for half an hour.
        PerformanceController::invalidateBenchmark($portfolio);

        $portfolio->refresh();

        return response()->json([
            'message' => 'Cash transaction amended.',
            'data' => [
                'cash_in_hand' => (float) $portfolio->cash_in_hand,
                'bank_balance' => (float) $portfolio->bank_balance,
                'investable_cash' => $portfolio->investableCash(),
            ],
        ]);
    }

    /**
     * Void a transaction entered entirely in error. It is soft-deleted
     * (`voided_at`/`void_reason`) rather than hard-deleted, so it stays
     * visible as an explanation if a user asks why the balance is what it
     * is, but no longer counts toward it and drops out of the default
     * ledger listing.
     */
    public function destroy(Request $request, Portfolio $portfolio, CashTransaction $transaction): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);
        $this->authorizeTransaction($portfolio, $transaction);

        $reason = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ])['reason'] ?? null;

        try {
            $this->ledger->void($portfolio, $transaction, $reason);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Cache::forget("portfolio:summary:{$portfolio->id}");
        // The benchmark is cached for far longer than the summary, so it needs
        // an explicit bump or a trade would not show up in it for half an hour.
        PerformanceController::invalidateBenchmark($portfolio);

        $portfolio->refresh();

        return response()->json([
            'message' => 'Cash transaction voided.',
            'data' => [
                'cash_in_hand' => (float) $portfolio->cash_in_hand,
                'bank_balance' => (float) $portfolio->bank_balance,
                'investable_cash' => $portfolio->investableCash(),
            ],
        ]);
    }

    private function authorizeTransaction(Portfolio $portfolio, CashTransaction $transaction): void
    {
        /*
         * A bare 404 on purpose. bootstrap/app.php flattens every api/*
         * NotFoundHttpException to "Not found.", and that is the right answer
         * here anyway: replying "not in this portfolio" would confirm the
         * record exists under some other portfolio, which is a small
         * disclosure on an authorization boundary. Passing a message here
         * would be dead text — it never reaches the client.
         */
        abort_unless($transaction->portfolio_id === $portfolio->id, 404);
    }
}
