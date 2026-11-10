<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPortfolioAccess;
use App\Http\Controllers\Controller;
use App\Models\Portfolio;
use App\Models\PortfolioImport;
use App\Services\Import\PortfolioImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PortfolioImportController extends Controller
{
    use AuthorizesPortfolioAccess;

    /** MeroShare exports are a few KB; anything larger is not one of them. */
    private const MAX_KB = 2048;

    public function __construct(
        private readonly PortfolioImportService $imports,
    ) {}

    /**
     * Import history for a portfolio.
     */
    public function index(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        $imports = $portfolio->hasMany(PortfolioImport::class)->getQuery()
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return response()->json([
            'data' => $imports->map(fn (PortfolioImport $import): array => [
                'id' => $import->id,
                'status' => $import->status,
                'holdings_filename' => $import->holdings_filename,
                'wacc_filename' => $import->wacc_filename,
                'holdings_count' => $import->holdings_count,
                'wacc_count' => $import->wacc_count,
                'committed_count' => $import->committed_count,
                'demat' => $import->demat_masked,
                'created_at' => $import->created_at?->toIso8601String(),
                'committed_at' => $import->committed_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * Step 1 — upload the "My Portfolio" export.
     */
    public function store(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        $request->validate([
            // `txt` is allowed because macOS sometimes reports a CSV as
            // text/plain, which would otherwise fail the mimes check.
            'file' => ['required', 'file', 'max:'.self::MAX_KB, 'mimes:csv,txt'],
        ], [
            'file.mimes' => 'Upload the CSV file exactly as MeroShare exported it.',
        ]);

        $file = $request->file('file');

        try {
            $import = $this->imports->startImport(
                $portfolio,
                (string) file_get_contents($file->getRealPath()),
                $file->getClientOriginalName(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->payload($import)], 201);
    }

    /**
     * Step 2 — upload the WACC export.
     */
    public function attachWacc(Request $request, Portfolio $portfolio, PortfolioImport $import): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);
        $this->authorizeImport($portfolio, $import);

        $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_KB, 'mimes:csv,txt'],
        ]);

        $file = $request->file('file');

        try {
            $import = $this->imports->attachWacc(
                $import,
                (string) file_get_contents($file->getRealPath()),
                $file->getClientOriginalName(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->payload($import)]);
    }

    public function show(Request $request, Portfolio $portfolio, PortfolioImport $import): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);
        $this->authorizeImport($portfolio, $import);

        return response()->json(['data' => $this->payload($import)]);
    }

    /**
     * Edit a staged row before committing — quantity, cost, or include/exclude.
     */
    public function updateRow(Request $request, Portfolio $portfolio, PortfolioImport $import): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);
        $this->authorizeImport($portfolio, $import);

        $validated = $request->validate([
            'symbol' => ['required', 'string', 'max:24'],
            'quantity' => ['sometimes', 'numeric', 'gte:0', 'max:999999999'],
            'average_cost' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:1000000'],
            'include' => ['sometimes', 'boolean'],
            'purchased_on' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
        ]);

        $symbol = $validated['symbol'];
        unset($validated['symbol']);

        try {
            $import = $this->imports->updateRow($import, $symbol, $validated);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->payload($import)]);
    }

    /**
     * Write the staged positions into the portfolio.
     */
    public function commit(Request $request, Portfolio $portfolio, PortfolioImport $import): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);
        $this->authorizeImport($portfolio, $import);

        $validated = $request->validate([
            'replace_existing' => ['nullable', 'boolean'],
        ]);

        try {
            $import = $this->imports->commit($import, $validated['replace_existing'] ?? true);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Imported {$import->committed_count} positions from MeroShare.",
            'data' => $this->payload($import),
        ]);
    }

    public function destroy(Request $request, Portfolio $portfolio, PortfolioImport $import): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);
        $this->authorizeImport($portfolio, $import);

        try {
            $this->imports->cancel($import);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Import cancelled.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PortfolioImport $import): array
    {
        return [
            'id' => $import->id,
            'status' => $import->status,
            'holdings_filename' => $import->holdings_filename,
            'wacc_filename' => $import->wacc_filename,
            'demat' => $import->demat_masked,
            'holdings_count' => $import->holdings_count,
            'wacc_count' => $import->wacc_count,
            'committed_count' => $import->committed_count,
            'summary' => $this->imports->summary($import),
            'warnings' => $import->warnings ?? [],
            'rows' => $import->merged_rows ?? [],
            'created_at' => $import->created_at?->toIso8601String(),
            'committed_at' => $import->committed_at?->toIso8601String(),
        ];
    }

    private function authorizeImport(Portfolio $portfolio, PortfolioImport $import): void
    {
        abort_unless(
            $import->portfolio_id === $portfolio->id,
            404,
            'That import does not belong to this portfolio.',
        );
    }
}
