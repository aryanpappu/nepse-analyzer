<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Enums\TransactionType;
use App\Models\Portfolio;
use App\Models\PortfolioHolding;
use App\Models\PortfolioTransaction;
use App\Models\Stock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single writer for holdings.
 *
 * Positions are always a function of the transaction ledger, so `quantity` and
 * `average_cost` are recomputed here rather than patched from a controller.
 * That is what keeps a portfolio reconcilable: you can always replay the
 * ledger and get the same position back.
 *
 * Both entry points use the same per-scrip chronological replay. Recording a
 * trade used to fold it into the current position incrementally, which gave a
 * different answer from `rebuild()` whenever the trade was backdated: a buy
 * dated before an existing sell changed the cost of the units sold, and a sell
 * dated before the buy that funded it was accepted. Replaying makes the
 * position after `record()` identical to the position after `rebuild()`.
 */
final class HoldingCalculator
{
    /**
     * Record a transaction and replay its scrip.
     *
     * The replay is strict: if the new transaction leaves any point in the
     * scrip's history selling more than was held at that date, nothing is
     * written and a `RuntimeException` names the offending sell.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(Portfolio $portfolio, Stock $stock, array $attributes): PortfolioTransaction
    {
        $type = $attributes['type'] instanceof TransactionType
            ? $attributes['type']
            : TransactionType::from((string) $attributes['type']);

        $quantity = round((float) ($attributes['quantity'] ?? 0), 2);
        $price = round((float) ($attributes['price'] ?? 0), 4);
        $commission = round((float) ($attributes['commission'] ?? 0), 2);
        $otherFees = round((float) ($attributes['other_fees'] ?? 0), 2);
        $transactedOn = Carbon::parse($attributes['transacted_on'] ?? Carbon::today());

        if ($quantity <= 0 && $type !== TransactionType::Dividend) {
            throw new RuntimeException('Transaction quantity must be greater than zero.');
        }

        return DB::transaction(function () use (
            $portfolio, $stock, $type, $quantity, $price,
            $commission, $otherFees, $transactedOn, $attributes
        ): PortfolioTransaction {
            // Lock the portfolio row so two concurrent trades serialise and the
            // second replay sees the first one's transaction. The holding row
            // is not enough: a scrip's first trade has no holding row to lock.
            Portfolio::whereKey($portfolio->id)->lockForUpdate()->first();

            // Oversells already in the history (imports clamp them) are not
            // this trade's fault; only one this trade introduces is refused.
            // Otherwise one old clamped sell would block every later trade.
            $tolerated = $this->replayScrip($portfolio, (int) $stock->id, strict: false, write: false)['oversold'];

            $transaction = PortfolioTransaction::create([
                'portfolio_id' => $portfolio->id,
                'stock_id' => $stock->id,
                'type' => $type->value,
                'quantity' => $quantity,
                'price' => $price,
                'commission' => $commission,
                'other_fees' => $otherFees,
                'realized_gain' => null,
                'transacted_on' => $transactedOn->toDateString(),
                'notes' => $attributes['notes'] ?? null,
            ]);

            // Throws on an oversell anywhere in the history, rolling back the
            // insert above with the surrounding transaction.
            $this->replayScrip($portfolio, (int) $stock->id, strict: true, symbol: (string) $stock->symbol, tolerated: $tolerated);

            return $transaction->refresh();
        });
    }

    /**
     * Replay the whole ledger for a portfolio and rewrite every position.
     *
     * Lenient on purpose: imported histories can sell parcels the ledger never
     * bought (IPO and bonus lots that arrived via CDSC), so an oversell is
     * clamped to what was held rather than refused.
     */
    public function rebuild(Portfolio $portfolio): int
    {
        return DB::transaction(function () use ($portfolio): int {
            $stockIds = $portfolio->transactions()->distinct()->pluck('stock_id')
                ->merge(PortfolioHolding::where('portfolio_id', $portfolio->id)->pluck('stock_id'))
                ->map(fn ($id): int => (int) $id)
                ->unique();

            $withTransactions = 0;

            foreach ($stockIds as $stockId) {
                if ($this->replayScrip($portfolio, $stockId, strict: false)['has_transactions']) {
                    $withTransactions++;
                }
            }

            return $withTransactions;
        });
    }

    /**
     * Replay one scrip's transactions in date order and write the position.
     *
     * Also rewrites each sell's and dividend's `realized_gain`, because a
     * backdated buy changes the average cost every later sell was booked at.
     *
     * @param  list<int>  $tolerated  sells allowed to oversell even when strict
     * @return array{has_transactions: bool, oversold: list<int>}
     */
    private function replayScrip(
        Portfolio $portfolio,
        int $stockId,
        bool $strict,
        string $symbol = '',
        array $tolerated = [],
        bool $write = true,
    ): array {
        /** @var Collection<int, PortfolioTransaction> $transactions */
        $transactions = PortfolioTransaction::where('portfolio_id', $portfolio->id)
            ->where('stock_id', $stockId)
            ->orderBy('transacted_on')
            ->orderBy('id')
            ->get();

        $quantityHeld = 0.0;
        $average = 0.0;
        $realized = 0.0;
        $totalFees = 0.0;
        $first = null;
        $oversold = [];

        foreach ($transactions as $transaction) {
            $quantity = (float) $transaction->quantity;
            $price = (float) $transaction->price;
            $fees = $transaction->fees();
            $gain = null;
            $type = $transaction->type instanceof TransactionType
                ? $transaction->type
                : TransactionType::from((string) $transaction->type);
            $onDate = Carbon::parse($transaction->transacted_on)->toDateString();

            switch ($type) {
                case TransactionType::Buy:
                case TransactionType::Rights:
                    // Weighted average, with fees capitalised into the cost
                    // base — which is what makes the reported gain a net gain.
                    $newQuantity = $quantityHeld + $quantity;
                    $totalCost = ($quantityHeld * $average) + ($quantity * $price) + $fees;
                    $quantityHeld = $newQuantity;
                    $average = $newQuantity > 0 ? round($totalCost / $newQuantity, 4) : 0.0;
                    break;

                case TransactionType::Bonus:
                    // Free shares: the cost base is unchanged, spread wider.
                    $newQuantity = $quantityHeld + $quantity;
                    $totalCost = $quantityHeld * $average;
                    $quantityHeld = $newQuantity;
                    $average = $newQuantity > 0 ? round($totalCost / $newQuantity, 4) : 0.0;
                    break;

                case TransactionType::Sell:
                    $oversells = $quantity > $quantityHeld + 0.001;

                    if ($oversells) {
                        $oversold[] = (int) $transaction->id;
                    }

                    if ($strict && $oversells && ! in_array((int) $transaction->id, $tolerated, true)) {
                        throw new RuntimeException(sprintf(
                            'Cannot sell %s units of %s on %s — the portfolio held %s on that date.',
                            self::trim($quantity),
                            $symbol,
                            $onDate,
                            self::trim($quantityHeld),
                        ));
                    }

                    // Realised gain is proceeds net of fees, less the average
                    // cost of the units sold. Average cost is preserved when the
                    // position closes so the history stays readable.
                    $sellable = min($quantity, $quantityHeld);
                    $gain = round((($sellable * $price) - $fees) - ($sellable * $average), 2);
                    $quantityHeld = round($quantityHeld - $sellable, 2);

                    if ($quantityHeld <= 0.001) {
                        $quantityHeld = 0.0;
                    }
                    break;

                case TransactionType::Dividend:
                    // Cash income: no position change, recorded as realised.
                    $gain = round($transaction->dividendCash(), 2);
                    break;
            }

            if ($gain !== null) {
                $realized += $gain;
            }

            $totalFees += $fees;

            if ($first === null && $type->increasesQuantity()) {
                $first = $onDate;
            }

            $stored = $transaction->realized_gain;

            if ($write && (($stored === null) !== ($gain === null) || ($gain !== null && abs((float) $stored - $gain) >= 0.005))) {
                $transaction->forceFill(['realized_gain' => $gain])->saveQuietly();
            }
        }

        if (! $write) {
            return ['has_transactions' => $transactions->isNotEmpty(), 'oversold' => $oversold];
        }

        $holding = PortfolioHolding::firstOrNew(['portfolio_id' => $portfolio->id, 'stock_id' => $stockId]);
        $holding->portfolio_id = $portfolio->id;
        $holding->stock_id = $stockId;
        $holding->forceFill([
            'quantity' => round($quantityHeld, 2),
            'average_cost' => round($average, 4),
            'realized_gain' => round($realized, 2),
            'total_fees' => round($totalFees, 2),
            'first_purchased_on' => $first,
        ]);

        if ($holding->exists || $transactions->isNotEmpty()) {
            $holding->save();
        }

        return ['has_transactions' => $transactions->isNotEmpty(), 'oversold' => $oversold];
    }

    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
