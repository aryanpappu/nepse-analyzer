<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\TransactionType;
use App\Models\Portfolio;
use App\Models\PortfolioImport;
use App\Models\Stock;
use App\Services\Portfolio\HoldingCalculator;
use App\Services\Scraper\InstrumentClassifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Two-step MeroShare import.
 *
 * Step 1 — "My Portfolio" export: what the demat account holds right now. This
 *          is authoritative for QUANTITY.
 * Step 2 — "WACC" export: the weighted average cost of every scrip the account
 *          has ever held. This is authoritative for COST BASIS, and it also
 *          lists scrips sold in full, which must not become open positions.
 *
 * The merge deliberately takes each number from the file that actually knows
 * it, rather than preferring one file wholesale: the WACC quantity goes stale
 * between a sale and MeroShare's next recalculation, so trusting it for
 * quantity would resurrect positions the user has already exited.
 */
final class PortfolioImportService
{
    /** Both files agree and the row is ready to import. */
    public const ROW_READY = 'ready';

    /** Held, but the WACC file has no cost for it — needs a price from the user. */
    public const ROW_MISSING_COST = 'missing_cost';

    /** In the WACC file but not held any more — a closed position. */
    public const ROW_SOLD = 'sold';

    /** Quantities disagree between the two files. */
    public const ROW_QUANTITY_MISMATCH = 'quantity_mismatch';

    /** The ticker is not in our stock table yet. */
    public const ROW_UNKNOWN_STOCK = 'unknown_stock';

    public function __construct(
        private readonly MeroshareCsvParser $parser,
        private readonly HoldingCalculator $calculator,
        private readonly InstrumentClassifier $classifier,
    ) {}

    /**
     * Step 1: stage the holdings file.
     */
    public function startImport(Portfolio $portfolio, string $contents, string $filename): PortfolioImport
    {
        $type = $this->parser->detectType($contents);

        if ($type !== 'holdings') {
            throw new RuntimeException(
                'That looks like the WACC file. Upload the "My Portfolio" export first — '
                .'it establishes which scrips you currently hold.'
            );
        }

        $rows = $this->parser->parseHoldings($contents);

        $import = PortfolioImport::create([
            'portfolio_id' => $portfolio->id,
            'user_id' => $portfolio->user_id,
            'source' => 'meroshare',
            'status' => PortfolioImport::STATUS_AWAITING_WACC,
            'holdings_filename' => $filename,
            'holdings_rows' => $rows,
            'holdings_count' => count($rows),
            'total_value' => round(array_sum(array_map(
                static fn (array $row): float => (float) ($row['value'] ?? 0),
                $rows,
            )), 2),
        ]);

        // Preview is available immediately, so the user sees their scrips before
        // uploading the second file.
        return $this->rebuildPreview($import);
    }

    /**
     * Step 2: attach the WACC file and merge.
     */
    public function attachWacc(PortfolioImport $import, string $contents, string $filename): PortfolioImport
    {
        $this->assertEditable($import);

        $type = $this->parser->detectType($contents);

        if ($type !== 'wacc') {
            throw new RuntimeException(
                'That looks like the "My Portfolio" export. This step needs the WACC file, '
                .'which carries the price you bought at.'
            );
        }

        $parsed = $this->parser->parseWacc($contents);

        $import->fill([
            'wacc_filename' => $filename,
            'wacc_rows' => $parsed['rows'],
            'wacc_count' => count($parsed['rows']),
            'demat_masked' => $this->parser->maskDemat($parsed['demat']),
            'status' => PortfolioImport::STATUS_READY,
        ])->save();

        return $this->rebuildPreview($import);
    }

    /**
     * Merge both files into the editable preview.
     */
    public function rebuildPreview(PortfolioImport $import): PortfolioImport
    {
        $holdings = collect($import->holdings_rows ?? [])->keyBy('symbol');
        $wacc = collect($import->wacc_rows ?? [])->keyBy('symbol');

        // Any edits the user already made must survive a re-merge.
        $edits = collect($import->merged_rows ?? [])
            ->filter(static fn (array $row): bool => (bool) ($row['edited'] ?? false))
            ->keyBy('symbol');

        $symbols = $holdings->keys()->merge($wacc->keys())->unique();
        $stocks = Stock::with('sector')->whereIn('symbol', $symbols)->get()->keyBy('symbol');

        // MeroShare only ever lists real NEPSE scrips, so a ticker we have not
        // seen is a gap in our own data, not bad input. Create a stub for any
        // currently-held scrip so the import is not blocked — this is how
        // mutual funds get in, since they do not appear in the daily traded
        // price table. Name and sector are filled by the next company crawl.
        $missing = $holdings->keys()->reject(fn (string $symbol): bool => $stocks->has($symbol));

        foreach ($missing as $symbol) {
            $verdict = $this->classifier->fromSymbol($symbol);
            $row = $holdings->get($symbol);

            // Seed the price from MeroShare's own last-traded-price column.
            // Without it the position would be excluded from every valuation
            // until the next scrape happens to pick the scrip up — and for a
            // fund that never appears in the traded table, that could be never.
            $seedPrice = $row === null || $row['last_price'] === null
                ? null
                : round((float) $row['last_price'], 2);

            $stock = Stock::create([
                'symbol' => $symbol,
                'name' => $symbol,
                'instrument_type' => $verdict ?? InstrumentClassifier::UNKNOWN,
                'classification_source' => $verdict === null
                    ? InstrumentClassifier::SOURCE_UNRESOLVED
                    : InstrumentClassifier::SOURCE_SYMBOL,
                'last_price' => $seedPrice,
                'last_traded_on' => $seedPrice === null ? null : Carbon::today()->toDateString(),
                'is_active' => true,
            ]);

            $stocks->put($symbol, $stock);

            Log::info('Created a stock stub during MeroShare import', [
                'symbol' => $symbol,
                'instrument_type' => $stock->instrument_type,
            ]);
        }

        $rows = [];
        $warnings = [];

        foreach ($symbols as $symbol) {
            $holding = $holdings->get($symbol);
            $cost = $wacc->get($symbol);
            $stock = $stocks->get($symbol);

            $heldQuantity = $holding === null ? 0.0 : (float) $holding['quantity'];
            $waccQuantity = $cost === null ? null : (float) $cost['quantity'];
            $rate = $cost === null ? null : (float) $cost['rate'];

            // Classify the row.
            if ($holding === null) {
                // Present only in WACC: a position that has been fully exited.
                $status = self::ROW_SOLD;
                $include = false;
            } elseif ($stock === null) {
                $status = self::ROW_UNKNOWN_STOCK;
                $include = false;
                $warnings[] = "{$symbol} is not in the stock list yet — run the NEPSE scrape, or add it manually.";
            } elseif ($rate === null || $rate <= 0) {
                $status = self::ROW_MISSING_COST;
                $include = false;
                $warnings[] = "{$symbol}: no purchase price in the WACC file. Enter one before importing.";
            } elseif ($waccQuantity !== null && abs($waccQuantity - $heldQuantity) > 0.01) {
                // MeroShare recalculates WACC lazily, so this is expected after
                // a recent trade. Quantity from the holdings file wins.
                $status = self::ROW_QUANTITY_MISMATCH;
                $include = true;
                $warnings[] = sprintf(
                    '%s: you hold %s but the WACC file says %s. Using %s, at the WACC rate.',
                    $symbol,
                    $this->trim($heldQuantity),
                    $this->trim($waccQuantity),
                    $this->trim($heldQuantity),
                );
            } else {
                $status = self::ROW_READY;
                $include = true;
            }

            $row = [
                'symbol' => $symbol,
                'name' => $stock?->name ?? $symbol,
                'sector' => $stock?->sector?->name,
                'instrument_type' => $stock?->instrument_type,
                'status' => $status,
                'include' => $include,
                'edited' => false,

                'quantity' => $heldQuantity,
                'wacc_quantity' => $waccQuantity,
                'average_cost' => $rate,
                'total_cost' => $rate === null ? null : round($heldQuantity * $rate, 2),

                'last_price' => $holding === null
                    ? ($stock?->last_price === null ? null : (float) $stock->last_price)
                    : ($holding['last_price'] === null ? null : (float) $holding['last_price']),
                'market_value' => $holding === null ? null : ($holding['value'] === null ? null : (float) $holding['value']),
                'purchased_on' => $cost['modified_at'] ?? null,

                // What the WACC file said before any manual edit, so the user
                // can always see the original.
                'source_average_cost' => $rate,
                'source_quantity' => $heldQuantity,
            ];

            // Re-apply the user's edits on top.
            if ($edits->has($symbol)) {
                $edit = $edits->get($symbol);
                $row['quantity'] = (float) ($edit['quantity'] ?? $row['quantity']);
                $row['average_cost'] = $edit['average_cost'] === null ? null : (float) $edit['average_cost'];
                $row['include'] = (bool) ($edit['include'] ?? $row['include']);
                $row['purchased_on'] = $edit['purchased_on'] ?? $row['purchased_on'];
                $row['edited'] = true;
                $row['total_cost'] = $row['average_cost'] === null
                    ? null
                    : round($row['quantity'] * $row['average_cost'], 2);

                if ($row['average_cost'] !== null && $row['average_cost'] > 0 && $row['status'] === self::ROW_MISSING_COST) {
                    $row['status'] = self::ROW_READY;
                }
            }

            $row['gain'] = $row['market_value'] !== null && $row['total_cost'] !== null
                ? round($row['market_value'] - $row['total_cost'], 2)
                : null;
            $row['gain_percent'] = $row['gain'] !== null && $row['total_cost'] > 0
                ? round($row['gain'] / $row['total_cost'] * 100, 2)
                : null;

            $rows[] = $row;
        }

        // Held positions first, then everything else, alphabetically within each.
        usort($rows, static function (array $a, array $b): int {
            $rank = static fn (array $row): int => match ($row['status']) {
                self::ROW_READY => 0,
                self::ROW_QUANTITY_MISMATCH => 1,
                self::ROW_MISSING_COST => 2,
                self::ROW_UNKNOWN_STOCK => 3,
                default => 4,
            };

            return [$rank($a), $a['symbol']] <=> [$rank($b), $b['symbol']];
        });

        $includable = array_filter($rows, static fn (array $row): bool => $row['include']);

        $import->fill([
            'merged_rows' => $rows,
            'warnings' => array_values(array_unique($warnings)),
            'total_cost' => round(array_sum(array_map(
                static fn (array $row): float => (float) ($row['total_cost'] ?? 0),
                $includable,
            )), 2),
        ])->save();

        return $import->refresh();
    }

    /**
     * Edit a staged row — the "modify the WACC" step.
     *
     * @param  array<string, mixed>  $changes
     */
    public function updateRow(PortfolioImport $import, string $symbol, array $changes): PortfolioImport
    {
        $this->assertEditable($import);

        $symbol = strtoupper(trim($symbol));
        $rows = $import->merged_rows ?? [];
        $found = false;

        foreach ($rows as $index => $row) {
            if ($row['symbol'] !== $symbol) {
                continue;
            }

            $found = true;

            if (array_key_exists('quantity', $changes)) {
                $rows[$index]['quantity'] = round((float) $changes['quantity'], 2);
            }

            if (array_key_exists('average_cost', $changes)) {
                $cost = $changes['average_cost'];
                $rows[$index]['average_cost'] = $cost === null ? null : round((float) $cost, 4);
            }

            if (array_key_exists('include', $changes)) {
                $rows[$index]['include'] = (bool) $changes['include'];
            }

            if (array_key_exists('purchased_on', $changes)) {
                $rows[$index]['purchased_on'] = $changes['purchased_on'];
            }

            $rows[$index]['edited'] = true;
            break;
        }

        if (! $found) {
            throw new RuntimeException("{$symbol} is not part of this import.");
        }

        $import->merged_rows = $rows;
        $import->save();

        // Re-merge so statuses, totals and warnings reflect the edit.
        return $this->rebuildPreview($import);
    }

    /**
     * Write the staged rows into the portfolio.
     *
     * @param  bool  $replaceExisting  Replace the positions this export speaks
     *                                 for. MeroShare is the system of record
     *                                 for what is held, so this is the usual
     *                                 choice — but see the scoping note below:
     *                                 it replaces per scrip, never wholesale.
     */
    public function commit(PortfolioImport $import, bool $replaceExisting = true): PortfolioImport
    {
        $this->assertEditable($import);

        $rows = collect($import->merged_rows ?? [])
            ->filter(static fn (array $row): bool => (bool) $row['include'])
            ->filter(static fn (array $row): bool => (float) $row['quantity'] > 0)
            ->filter(static fn (array $row): bool => $row['average_cost'] !== null && (float) $row['average_cost'] > 0);

        if ($rows->isEmpty()) {
            throw new RuntimeException(
                'Nothing is ready to import. Every row is either excluded or still missing a purchase price.'
            );
        }

        $portfolio = $import->portfolio;
        $stocks = Stock::whereIn('symbol', $rows->pluck('symbol'))->get()->keyBy('symbol');

        $imported = 0;

        DB::transaction(function () use ($rows, $portfolio, $stocks, $replaceExisting, &$imported): void {
            if ($replaceExisting) {
                /*
                 * Scoped to the scrips this export actually lists, mirroring
                 * BrokerStatementImporter::clearExisting(). A "My Portfolio"
                 * export is a snapshot of what is held *now*, so a scrip sold
                 * out completely is simply absent from it — and an unscoped
                 * `transactions()->delete()` read that absence as "this scrip
                 * never existed" and erased its whole history.
                 *
                 * For example, fictional parcels TESTA and TESTB could be fully
                 * disposed of after arriving through CDSC, so MeroShare would
                 * not show them. Wiping them would remove their realised gains
                 * and capital-gains history.
                 *
                 * The rule, the same one the statement side follows: an export
                 * replaces what it has an opinion about and stays silent about
                 * the rest. Silence must never mean deletion.
                 */
                $stockIds = $stocks->pluck('id');

                DB::table('portfolio_transactions')
                    ->where('portfolio_id', $portfolio->id)
                    ->whereIn('stock_id', $stockIds)
                    ->delete();

                // Positions are a function of the ledger, so they are dropped
                // wholesale and rebuilt below from whatever survives.
                DB::table('portfolio_holdings')->where('portfolio_id', $portfolio->id)->delete();
            }

            foreach ($rows as $row) {
                $stock = $stocks->get($row['symbol']);

                if ($stock === null) {
                    continue;
                }

                // Recorded as a single opening buy at the WACC rate with zero
                // fees: the WACC rate is already fee-inclusive, so adding a
                // commission on top would overstate the cost base.
                $this->calculator->record($portfolio, $stock, [
                    'type' => TransactionType::Buy,
                    'quantity' => (float) $row['quantity'],
                    'price' => (float) $row['average_cost'],
                    'commission' => 0,
                    'other_fees' => 0,
                    'transacted_on' => $this->purchaseDate($row),
                    'notes' => 'Imported from MeroShare (WACC rate).',
                ]);

                $imported++;
            }

            if ($replaceExisting) {
                /*
                 * Holdings were dropped wholesale above, so every surviving
                 * scrip — the ones this export said nothing about — needs its
                 * position replayed from the transactions that remain.
                 * Without this they would silently vanish from the portfolio
                 * even though their ledger is intact.
                 */
                $this->calculator->rebuild($portfolio);
            }
        });

        $import->fill([
            'status' => PortfolioImport::STATUS_COMMITTED,
            'committed_count' => $imported,
            'committed_at' => Carbon::now(),
        ])->save();

        Log::info('MeroShare import committed', [
            'import_id' => $import->id,
            'portfolio_id' => $portfolio->id,
            'positions' => $imported,
            'replaced' => $replaceExisting,
        ]);

        return $import->refresh();
    }

    public function cancel(PortfolioImport $import): PortfolioImport
    {
        $this->assertEditable($import);

        $import->update(['status' => PortfolioImport::STATUS_CANCELLED]);

        return $import;
    }

    /**
     * Summary counts for the wizard UI.
     *
     * @return array<string, mixed>
     */
    public function summary(PortfolioImport $import): array
    {
        $rows = collect($import->merged_rows ?? []);

        return [
            'total_rows' => $rows->count(),
            'ready' => $rows->where('status', self::ROW_READY)->count(),
            'quantity_mismatch' => $rows->where('status', self::ROW_QUANTITY_MISMATCH)->count(),
            'missing_cost' => $rows->where('status', self::ROW_MISSING_COST)->count(),
            'unknown_stock' => $rows->where('status', self::ROW_UNKNOWN_STOCK)->count(),
            'sold' => $rows->where('status', self::ROW_SOLD)->count(),
            'included' => $rows->where('include', true)->count(),
            'total_cost' => $import->total_cost === null ? null : (float) $import->total_cost,
            'total_value' => $import->total_value === null ? null : (float) $import->total_value,
            'funds_held' => $rows->where('include', true)->where('instrument_type', 'mutual_fund')->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function purchaseDate(array $row): Carbon
    {
        $date = $row['purchased_on'] ?? null;

        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $parsed = Carbon::parse($date);

            // A WACC modification date can be in the future relative to a
            // wrongly-set clock; never record a trade ahead of today.
            return $parsed->isFuture() ? Carbon::today() : $parsed;
        }

        return Carbon::today();
    }

    private function assertEditable(PortfolioImport $import): void
    {
        if (! $import->isEditable()) {
            throw new RuntimeException("This import is already {$import->status} and cannot be changed.");
        }
    }

    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
