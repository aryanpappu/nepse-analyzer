<?php

declare(strict_types=1);

namespace App\Services\Playbook\Backtest;

/**
 * One plan, played forward against the bars that followed it.
 *
 * Every money and return field is nullable and stays null on an unfilled or
 * unresolved plan. This is the project's `n/a, never 0` rule applied where it
 * matters most: a zero here would be averaged into a hit rate and an average
 * return as though the strategy had traded and broken even.
 */
final class SimulatedTrade
{
    public function __construct(
        public readonly TradeSubject $subject,
        public readonly string $signalDate,
        public readonly TradeOutcome $outcome,
        public readonly ?string $entryDate = null,
        public readonly ?float $entryPrice = null,
        public readonly ?string $exitDate = null,
        public readonly ?float $exitPrice = null,
        public readonly ?int $holdSessions = null,
        public readonly ?int $quantity = null,
        public readonly ?float $buyTurnover = null,
        public readonly ?float $sellTurnover = null,
        public readonly ?float $buyCosts = null,
        public readonly ?float $sellCosts = null,
        public readonly ?float $grossProfit = null,
        public readonly ?float $netProfit = null,
        public readonly ?float $grossReturnPercent = null,
        public readonly ?float $netReturnPercent = null,
        public readonly ?float $maxFavourableExcursionPercent = null,
        public readonly ?float $maxAdverseExcursionPercent = null,
    ) {}

    public static function unfilled(TradeSubject $subject, string $signalDate, TradeOutcome $outcome): self
    {
        return new self(subject: $subject, signalDate: $signalDate, outcome: $outcome);
    }

    public function isResolved(): bool
    {
        return $this->outcome->isResolved() && $this->netReturnPercent !== null;
    }

    public function totalCosts(): ?float
    {
        if ($this->buyCosts === null || $this->sellCosts === null) {
            return null;
        }

        return round($this->buyCosts + $this->sellCosts, 4);
    }

    /**
     * Capital actually tied up: the consideration plus the buy-side fees you
     * had to find at the same moment. Bare turnover as the denominator would
     * understate what the trade cost to put on.
     */
    public function capitalDeployed(): ?float
    {
        if ($this->buyTurnover === null || $this->buyCosts === null) {
            return null;
        }

        return round($this->buyTurnover + $this->buyCosts, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'symbol' => $this->subject->symbol,
            'profile' => $this->subject->profile->value,
            'grade' => $this->subject->grade->value,
            'score' => round($this->subject->score, 2),
            'signal_date' => $this->signalDate,
            'outcome' => $this->outcome->value,
            'entry_date' => $this->entryDate,
            'entry_price' => $this->entryPrice,
            'exit_date' => $this->exitDate,
            'exit_price' => $this->exitPrice,
            'hold_sessions' => $this->holdSessions,
            'quantity' => $this->quantity,
            'costs' => $this->totalCosts(),
            'gross_return_percent' => $this->grossReturnPercent,
            'net_return_percent' => $this->netReturnPercent,
            'mfe_percent' => $this->maxFavourableExcursionPercent,
            'mae_percent' => $this->maxAdverseExcursionPercent,
        ];
    }
}
