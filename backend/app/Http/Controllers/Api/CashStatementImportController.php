<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPortfolioAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCashStatementImportRequest;
use App\Http\Requests\UpdateCashStatementRowRequest;
use App\Models\CashStatementImport;
use App\Models\Portfolio;
use App\Services\Import\CashStatementImportService;
use App\Services\Import\XlsxReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Import a broker cash statement into the ledger.
 *
 * The two MeroShare imports answer "what do I hold" and "what did I pay".
 * Neither answers "what money did I actually put in", and without that every
 * money-weighted figure in the app — XIRR, the deposit benchmark, the index
 * benchmark — is computed against whatever placeholder cash exists.
 *
 * Staged and reviewable like the MeroShare wizard: nothing reaches the ledger
 * until commit, FAILED rows and suspected duplicates are excluded by default
 * with their reason shown, and the commit writes through CashLedger so
 * balances stay reconstructable.
 */
class CashStatementImportController extends Controller
{
    use AuthorizesPortfolioAccess;

    public function __construct(
        private readonly CashStatementImportService $imports,
    ) {}

    public function index(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        $rows = CashStatementImport::query()
            ->where('portfolio_id', $portfolio->id)
            ->orderByDesc('id')
            ->limit((int) $request->integer('limit', 20))
            ->get();

        return response()->json([
            'data' => $rows->map(fn (CashStatementImport $i): array => $this->imports->summary($i))->all(),
        ]);
    }

    public function store(StoreCashStatementImportRequest $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        $file = $request->file('file');
        $path = $file->getRealPath();
        $account = $request->validated()['account'] ?? null;

        try {
            /*
             * The broker's own .xlsx goes to the parser that understands its
             * column grid. Sniffed from the file itself rather than trusted
             * from the extension, which a re-saved download routinely gets
             * wrong.
             */
            $import = (new XlsxReader)->looksLikeXlsx($path)
                ? $this->imports->startFromXlsx(
                    $portfolio,
                    $path,
                    (string) $file->getClientOriginalName(),
                    $account,
                )
                : $this->imports->startImport(
                    $portfolio,
                    (string) file_get_contents($path),
                    (string) $file->getClientOriginalName(),
                    $request->validated()['format'] ?? null,
                    $account,
                );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->imports->detail($import)], 201);
    }

    public function show(Request $request, Portfolio $portfolio, CashStatementImport $import): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);
        $this->authorizeImport($portfolio, $import);

        return response()->json(['data' => $this->imports->detail($import)]);
    }

    public function updateRow(
        UpdateCashStatementRowRequest $request,
        Portfolio $portfolio,
        CashStatementImport $import,
    ): JsonResponse {
        $this->authorizeOwner($request, $portfolio);
        $this->authorizeImport($portfolio, $import);

        $validated = $request->validated();
        $key = $validated['key'];
        unset($validated['key']);

        try {
            $import = $this->imports->updateRow($import, $key, $validated);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->imports->detail($import)]);
    }

    public function commit(Request $request, Portfolio $portfolio, CashStatementImport $import): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);
        $this->authorizeImport($portfolio, $import);

        $validated = $request->validate([
            // A statement that starts mid-history carries a balance in from
            // before its first row; recorded as a real dated deposit so the
            // replay does not overdraw on the first withdrawal.
            'opening_balance' => ['nullable', 'numeric', 'gte:0'],
        ]);

        try {
            $import = $this->imports->commit(
                $import,
                isset($validated['opening_balance']) ? (float) $validated['opening_balance'] : null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Cache::forget("portfolio:summary:{$portfolio->id}");
        PerformanceController::invalidateBenchmark($portfolio);

        return response()->json([
            'message' => 'Cash statement committed to the ledger.',
            'data' => $this->imports->summary($import),
        ]);
    }

    public function destroy(Request $request, Portfolio $portfolio, CashStatementImport $import): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);
        $this->authorizeImport($portfolio, $import);

        try {
            $import = $this->imports->cancel($import);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->imports->summary($import)]);
    }

    private function authorizeImport(Portfolio $portfolio, CashStatementImport $import): void
    {
        // Message-less by design; see the note in CashController.
        abort_unless($import->portfolio_id === $portfolio->id, 404);
    }
}
