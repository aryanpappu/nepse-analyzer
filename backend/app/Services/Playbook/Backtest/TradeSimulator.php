<?php

declare(strict_types=1);

namespace App\Services\Playbook\Backtest;

/**
 * Plays one {@see TradePlan} forward against the bars that followed it.
 *
 * A pure function of (plan, forward bars, notional) — no database, no clock, no
 * config — which is what makes the core of the backtest testable in a suite
 * that cannot touch MySQL, and what makes every reported figure reproducible.
 *
 * The rules, and why each one is the pessimistic reading:
 *
 * - **The signal bar is never traded.** The verdict is scored on a close, so
 *   the earliest possible fill is the *next* bar. The caller passes
 *   `BarSeries::after($signalDate)`, so the signal bar's own high and low are
 *   not even in the data this class can see.
 * - **Fill is a resting limit buy at `entryHigh`**, valid for the entry
 *   window: it fills on the first forward bar that trades at or below that
 *   limit, at `min(open, entryHigh)` — the open when the market was already
 *   there, the limit price when it had to come down to us. Standard limit
 *   semantics, and the only rule that does not invent a price the tape never
 *   printed.
 * - **A plan that gaps through its own stop is void, not filled.** If the fill
 *   price would sit at or below the stop, the setup broke before it could be
 *   entered; buying there and being stopped on the same bar would manufacture a
 *   full-size loss out of a trade nobody would have taken.
 * - **No fill inside the entry window means no trade.** Not a flat trade.
 * - **Stop is checked before target on the same bar.** Daily bars cannot say
 *   which came first, and assuming the target did would manufacture winners
 *   out of exactly the volatile bars where the assumption is least safe.
 * - **Gaps are honoured against the position.** After the fill bar, a bar that
 *   opens below the stop exits at that open, not at the stop; one that opens
 *   above the target exits at the target's own level only if the open is not
 *   already through it.
 * - **The hold ceiling closes the trade at a close**, not at a level, because
 *   that is what a hold-window exit actually is.
 * - **Data running out is `Unresolved`, never a mark to the last close.** In a
 *   market that rose over the window, marking open trades to the last bar
 *   turns every unfinished position into a winner.
 */
final class TradeSimulator
{
    public function __construct(
        private readonly TradeCostCalculator $costs,
    ) {}

    /**
     * @param  BarSeries  $forward  Bars strictly after the plan's signal date, ascending.
     * @param  float  $notional  Rupees to deploy per trade, before costs.
     */
    public function simulate(TradePlan $plan, BarSeries $forward, TradeSubject $subject, float $notional): SimulatedTrade
    {
        $bars = $forward->bars();

        if ($bars === []) {
            return SimulatedTrade::unfilled($subject, $plan->signalDate, TradeOutcome::Unresolved);
        }

        $fill = $this->findFill($plan, $bars);

        if ($fill === null || isset($fill['void'])) {
            // Distinguish "the market declined the plan" from "we ran out of
            // bars before the entry window closed" — the first is a fact about
            // the strategy, the second a fact about the database.
            $outcome = $fill === null && count($bars) < $plan->entryWindowSessions
                ? TradeOutcome::Unresolved
                : TradeOutcome::NoFill;

            return SimulatedTrade::unfilled($subject, $plan->signalDate, $outcome);
        }

        $fillIndex = $fill['index'];
        $entryPrice = $fill['price'];

        if ($entryPrice <= 0.0) {
            return SimulatedTrade::unfilled($subject, $plan->signalDate, TradeOutcome::Unresolved);
        }

        $exit = $this->findExit($plan, $bars, $fillIndex, $entryPrice);

        if ($exit === null) {
            return SimulatedTrade::unfilled($subject, $plan->signalDate, TradeOutcome::Unresolved);
        }

        return $this->settle($plan, $subject, $bars, $fillIndex, $entryPrice, $exit, $notional);
    }

    /**
     * @param  list<Bar>  $bars
     * @return array{index: int, price: float}|array{void: true}|null
     */
    private function findFill(TradePlan $plan, array $bars): ?array
    {
        $limit = min(count($bars), $plan->entryWindowSessions);

        for ($i = 0; $i < $limit; $i++) {
            $bar = $bars[$i];

            if ($bar->lowOrClose() > $plan->entryHigh) {
                // The market never came down to the limit on this bar.
                continue;
            }

            $price = min($bar->openOrClose(), $plan->entryHigh);

            if ($price <= $plan->stopLoss) {
                // The bar opened at or through the level that invalidates the
                // plan. Nobody puts this trade on; it is not a loss, it is a
                // trade that never happened.
                return ['void' => true];
            }

            return ['index' => $i, 'price' => $price];
        }

        return null;
    }

    /**
     * @param  list<Bar>  $bars
     * @return array{index: int, price: float, outcome: TradeOutcome, mfe: float, mae: float}|null
     */
    private function findExit(TradePlan $plan, array $bars, int $fillIndex, float $entryPrice): ?array
    {
        $total = count($bars);
        $windowEnd = $fillIndex + $plan->maxHoldSessions;   // exclusive
        $scanEnd = min($total, $windowEnd);

        $mfe = 0.0;
        $mae = 0.0;

        for ($i = $fillIndex; $i < $scanEnd; $i++) {
            $bar = $bars[$i];
            $high = $bar->highOrClose();
            $low = $bar->lowOrClose();
            $open = $bar->openOrClose();
            $gapAware = $i > $fillIndex;

            $mfe = max($mfe, ($high - $entryPrice) / $entryPrice);
            $mae = min($mae, ($low - $entryPrice) / $entryPrice);

            if ($low <= $plan->stopLoss) {
                $price = $gapAware && $open < $plan->stopLoss ? $open : $plan->stopLoss;

                return ['index' => $i, 'price' => $price, 'outcome' => TradeOutcome::Stop, 'mfe' => $mfe, 'mae' => $mae];
            }

            if ($high >= $plan->target) {
                $price = $gapAware && $open > $plan->target ? $open : $plan->target;

                return ['index' => $i, 'price' => $price, 'outcome' => TradeOutcome::Target, 'mfe' => $mfe, 'mae' => $mae];
            }
        }

        if ($scanEnd < $windowEnd) {
            // The hold window has not closed yet and there are no more bars.
            return null;
        }

        $last = $bars[$scanEnd - 1];

        return [
            'index' => $scanEnd - 1,
            'price' => $last->close,
            'outcome' => TradeOutcome::Expired,
            'mfe' => $mfe,
            'mae' => $mae,
        ];
    }

    /**
     * @param  list<Bar>  $bars
     * @param  array{index: int, price: float, outcome: TradeOutcome, mfe: float, mae: float}  $exit
     */
    private function settle(
        TradePlan $plan,
        TradeSubject $subject,
        array $bars,
        int $fillIndex,
        float $entryPrice,
        array $exit,
        float $notional,
    ): SimulatedTrade {
        // Whole shares: NEPSE does not trade fractions, and rounding down is
        // the direction that cannot overstate the position.
        $quantity = max(1, (int) floor($notional / $entryPrice));

        $buyTurnover = round($quantity * $entryPrice, 2);
        $sellTurnover = round($quantity * $exit['price'], 2);

        $buyCosts = $this->costs->buyCost($buyTurnover);
        $sellCosts = $this->costs->sellCost($sellTurnover);

        $grossProfit = $sellTurnover - $buyTurnover;
        $netProfit = $grossProfit - $buyCosts - $sellCosts;
        $deployed = $buyTurnover + $buyCosts;

        return new SimulatedTrade(
            subject: $subject,
            signalDate: $plan->signalDate,
            outcome: $exit['outcome'],
            entryDate: $bars[$fillIndex]->date,
            entryPrice: round($entryPrice, 2),
            exitDate: $bars[$exit['index']]->date,
            exitPrice: round($exit['price'], 2),
            holdSessions: $exit['index'] - $fillIndex + 1,
            quantity: $quantity,
            buyTurnover: $buyTurnover,
            sellTurnover: $sellTurnover,
            buyCosts: round($buyCosts, 4),
            sellCosts: round($sellCosts, 4),
            grossProfit: round($grossProfit, 2),
            netProfit: round($netProfit, 2),
            grossReturnPercent: round($grossProfit / $buyTurnover * 100, 4),
            netReturnPercent: round($netProfit / $deployed * 100, 4),
            maxFavourableExcursionPercent: round($exit['mfe'] * 100, 4),
            maxAdverseExcursionPercent: round($exit['mae'] * 100, 4),
        );
    }
}
