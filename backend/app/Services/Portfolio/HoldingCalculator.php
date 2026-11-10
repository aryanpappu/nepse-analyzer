<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Enums\TransactionType;
use App\Models\Portfolio;
use App\Models\PortfolioHolding;
use App\Models\PortfolioTransaction;
use App\Models\Stock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single writer for holdings.
 *
 * Positions are always a function of the transaction ledger, so `quantity` and
 * `average_cost` are recomputed here rather than patched from a controller.
 * That is what keeps a portfolio reconcilable: you can always replay the
 * ledger and get the same position back.
 */
final class HoldingCalculator
{
    /**
     * Record a transaction and fold it into the position.
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
            // Lock the holding row so two concurrent trades on the same scrip
            // cannot both read the same average cost and clobber each other.
            $holding = PortfolioHolding::where('portfolio_id', $portfolio->id)
                ->where('stock_id', $stock->id)
                ->lockForUpdate()
                ->first();

            if ($holding === null) {
                $holding = new PortfolioHolding([
                    'portfolio_id' => $portfolio->id,
                    'stock_id' => $stock->id,
                    'quantity' => 0,
                    'average_cost' => 0,
                    'realized_gain' => 0,
                    'total_fees' => 0,
                ]);
                $holding->portfolio_id = $portfolio->id;
                $holding->stock_id = $stock->id;
            }

            $currentQuantity = (float) $holding->quantity;
            $currentAverage = (float) $holding->average_cost;
            $fees = $commission + $otherFees;
            $realizedGain = null;

            switch ($type) {
                case TransactionType::Buy:
                case TransactionType::Rights:
                    // Weighted average, with fees capitalised into the cost base
                    // — which is what makes the reported gain a true net gain.
                    $newQuantity = $currentQuantity + $quantity;
                    $totalCost = ($currentQuantity * $currentAverage) + ($quantity * $price) + $fees;
                    $holding->quantity = $newQuantity;
                    $holding->average_cost = $newQuantity > 0 ? round($totalCost / $newQuantity, 4) : 0;
                    break;

                case TransactionType::Bonus:
                    // Free shares: the cost base is unchanged, spread wider.
                    $newQuantity = $currentQuantity + $quantity;
                    $totalCost = $currentQuantity * $currentAverage;
                    $holding->quantity = $newQuantity;
                    $holding->average_cost = $newQuantity > 0 ? round($totalCost / $newQuantity, 4) : 0;
                    break;

                case TransactionType::Sell:
                    if ($quantity > $currentQuantity + 0.001) {
                        throw new RuntimeException(sprintf(
                            'Cannot sell %s units of %s — the portfolio holds %s.',
                            rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.'),
                            $stock->symbol,
                            rtrim(rtrim(number_format($currentQuantity, 2, '.', ''), '0'), '.'),
                        ));
                    }

                    // Realised gain is proceeds net of fees, less the average
                    // cost of the units sold.
                    $proceeds = ($quantity * $price) - $fees;
                    $costOfSold = $quantity * $currentAverage;
                    $realizedGain = round($proceeds - $costOfSold, 2);

                    $holding->quantity = round($currentQuantity - $quantity, 2);
                    $holding->realized_gain = round((float) $holding->realized_gain + $realizedGain, 2);

                    // Average cost is intentionally preserved when the position
                    // closes, so the history stays readable; it is only reset
                    // when the position is re-opened by a later buy.
                    if ((float) $holding->quantity <= 0.001) {
                        $holding->quantity = 0;
                    }
                    break;

                case TransactionType::Dividend:
                    // Cash income: no position change, recorded as realised.
                    $realizedGain = round(($quantity * $price) - $fees, 2);

                    if (abs($price) < 1e-9) {
                        // Dividends are often entered as a lump sum in `quantity`.
                        $realizedGain = round($quantity - $fees, 2);
                    }

                    $holding->realized_gain = round((float) $holding->realized_gain + $realizedGain, 2);
                    break;
            }

            $holding->total_fees = round((float) $holding->total_fees + $fees, 2);

            if ($holding->first_purchased_on === null && $type->increasesQuantity()) {
                $holding->first_purchased_on = $transactedOn->toDateString();
            }

            $holding->save();

            return PortfolioTransaction::create([
                'portfolio_id' => $portfolio->id,
                'stock_id' => $stock->id,
                'type' => $type->value,
                'quantity' => $quantity,
                'price' => $price,
                'commission' => $commission,
                'other_fees' => $otherFees,
                'realized_gain' => $realizedGain,
                'transacted_on' => $transactedOn->toDateString(),
                'notes' => $attributes['notes'] ?? null,
            ]);
        });
    }

    /**
     * Replay the whole ledger for a portfolio and rewrite every position.
     *
     * Used after a transaction is edited or deleted, where an incremental
     * update cannot produce the right answer.
     */
    public function rebuild(Portfolio $portfolio): int
    {
        return DB::transaction(function () use ($portfolio): int {
            $transactions = $portfolio->transactions()
                ->orderBy('transacted_on')
                ->orderBy('id')
                ->get();

            // Zero every position first, so scrips whose transactions were all
            // deleted do not keep a stale quantity.
            PortfolioHolding::where('portfolio_id', $portfolio->id)->update([
                'quantity' => 0,
                'average_cost' => 0,
                'realized_gain' => 0,
                'total_fees' => 0,
                'first_purchased_on' => null,
            ]);

            /** @var array<int, array{quantity: float, average: float, realized: float, fees: float, first: ?string}> $positions */
            $positions = [];

            foreach ($transactions as $transaction) {
                $stockId = $transaction->stock_id;
                $positions[$stockId] ??= [
                    'quantity' => 0.0, 'average' => 0.0, 'realized' => 0.0, 'fees' => 0.0, 'first' => null,
                ];

                $state = &$positions[$stockId];
                $quantity = (float) $transaction->quantity;
                $price = (float) $transaction->price;
                $fees = $transaction->fees();

                switch ($transaction->type) {
                    case TransactionType::Buy:
                    case TransactionType::Rights:
                        $newQuantity = $state['quantity'] + $quantity;
                        $totalCost = ($state['quantity'] * $state['average']) + ($quantity * $price) + $fees;
                        $state['quantity'] = $newQuantity;
                        $state['average'] = $newQuantity > 0 ? round($totalCost / $newQuantity, 4) : 0.0;
                        break;

                    case TransactionType::Bonus:
                        $newQuantity = $state['quantity'] + $quantity;
                        $totalCost = $state['quantity'] * $state['average'];
                        $state['quantity'] = $newQuantity;
                        $state['average'] = $newQuantity > 0 ? round($totalCost / $newQuantity, 4) : 0.0;
                        break;

                    case TransactionType::Sell:
                        $sellable = min($quantity, $state['quantity']);
                        $state['realized'] += round((($sellable * $price) - $fees) - ($sellable * $state['average']), 2);
                        $state['quantity'] = round($state['quantity'] - $sellable, 2);
                        break;

                    case TransactionType::Dividend:
                        $state['realized'] += abs($price) < 1e-9
                            ? round($quantity - $fees, 2)
                            : round(($quantity * $price) - $fees, 2);
                        break;
                }

                $state['fees'] += $fees;

                if ($state['first'] === null && $transaction->type->increasesQuantity()) {
                    $state['first'] = $transaction->transacted_on->toDateString();
                }

                unset($state);
            }

            foreach ($positions as $stockId => $state) {
                PortfolioHolding::updateOrCreate(
                    ['portfolio_id' => $portfolio->id, 'stock_id' => $stockId],
                    [
                        'quantity' => round($state['quantity'], 2),
                        'average_cost' => round($state['average'], 4),
                        'realized_gain' => round($state['realized'], 2),
                        'total_fees' => round($state['fees'], 2),
                        'first_purchased_on' => $state['first'],
                    ],
                );
            }

            return count($positions);
        });
    }
}
