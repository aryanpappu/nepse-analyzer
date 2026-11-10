<?php

declare(strict_types=1);

namespace App\Services\Playbook\Backtest;

/**
 * A slice of the backtest against the NEPSE index.
 *
 * "`strong_buy` beat `avoid`" is a weak claim — both could be losing money in
 * a rising market. The claim that decides whether the engine earns its place
 * is "`strong_buy` beat the index", and it needs two different comparisons
 * because they answer different objections:
 *
 * - **Matched windows.** For each trade, the index's own return over exactly
 *   that trade's entry-to-exit dates. Averaged, this is the alpha of the
 *   selection *and the timing*: the strategy is not credited for being in the
 *   market during a rally, only for beating the market while it was in.
 * - **Buy and hold.** The index over the whole window, plus the money-weighted
 *   "every rupee the strategy deployed bought index units on the same date and
 *   was still held at the window end" figure, from
 *   `IndexReturnCalculator::simulateInvestment()`. That is the "why not just
 *   buy the index and do nothing" objection, in rupees.
 *
 * Anything the index series cannot price is null and counted, never zero.
 */
final class IndexComparison
{
    /**
     * @param  list<string>  $uncoveredDates
     */
    public function __construct(
        public readonly string $indexSlug,
        public readonly ?string $indexName,
        public readonly ?float $windowReturnPercent,
        public readonly bool $windowComplete,
        public readonly ?float $coveragePercent,
        public readonly int $matchedTrades,
        public readonly int $unmatchedTrades,
        public readonly ?float $averageIndexReturnPercent,
        public readonly ?float $averageStrategyNetReturnPercent,
        public readonly ?float $averageStrategyGrossReturnPercent,
        public readonly ?float $averageExcessNetPercent,
        public readonly ?float $averageExcessGrossPercent,
        public readonly ?float $winVersusIndexPercent,
        public readonly ?float $strategyTerminalValue,
        public readonly ?float $indexBuyAndHoldValue,
        public readonly ?float $terminalDifference,
        public readonly array $uncoveredDates,
    ) {}

    public static function unavailable(string $slug, ?string $name, string $reason): self
    {
        return new self(
            indexSlug: $slug,
            indexName: $name,
            windowReturnPercent: null,
            windowComplete: false,
            coveragePercent: null,
            matchedTrades: 0,
            unmatchedTrades: 0,
            averageIndexReturnPercent: null,
            averageStrategyNetReturnPercent: null,
            averageStrategyGrossReturnPercent: null,
            averageExcessNetPercent: null,
            averageExcessGrossPercent: null,
            winVersusIndexPercent: null,
            strategyTerminalValue: null,
            indexBuyAndHoldValue: null,
            terminalDifference: null,
            uncoveredDates: [$reason],
        );
    }

    /**
     * Did the slice beat the index over the windows it was actually invested,
     * net of costs? Null when nothing could be matched — the one answer that
     * must never default to "no".
     */
    public function beatsIndexNet(): ?bool
    {
        return $this->averageExcessNetPercent === null ? null : $this->averageExcessNetPercent > 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'index_slug' => $this->indexSlug,
            'index_name' => $this->indexName,
            'window_return_percent' => $this->windowReturnPercent,
            'window_complete' => $this->windowComplete,
            'coverage_percent' => $this->coveragePercent,
            'matched_trades' => $this->matchedTrades,
            'unmatched_trades' => $this->unmatchedTrades,
            'average_index_return_percent' => $this->averageIndexReturnPercent,
            'average_strategy_net_return_percent' => $this->averageStrategyNetReturnPercent,
            'average_strategy_gross_return_percent' => $this->averageStrategyGrossReturnPercent,
            'average_excess_net_percent' => $this->averageExcessNetPercent,
            'average_excess_gross_percent' => $this->averageExcessGrossPercent,
            'win_versus_index_percent' => $this->winVersusIndexPercent,
            'strategy_terminal_value' => $this->strategyTerminalValue,
            'index_buy_and_hold_value' => $this->indexBuyAndHoldValue,
            'terminal_difference' => $this->terminalDifference,
            'beats_index_net' => $this->beatsIndexNet(),
            'uncovered_dates' => $this->uncoveredDates,
        ];
    }
}
