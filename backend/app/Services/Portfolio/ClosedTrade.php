<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use Illuminate\Support\Carbon;

/**
 * One round trip: units bought, later sold, and what it earned.
 *
 * Reconstructed rather than stored, because the ledger records transactions,
 * not trades. The gain uses the same weighted-average cost the rest of the app
 * uses — so the numbers here always reconcile with a holding's realized_gain —
 * while the acquisition date is matched FIFO, which is the only way to say how
 * long the money was actually at work.
 *
 * `acquisitionDateKnown` is the one field that is not arithmetic. A sell with
 * no matching purchase lot — an imported position whose history predates the
 * ledger — falls back to the sell date, which reads as a zero-day hold. That
 * is harmless in a "how long do I typically hold" average and actively wrong
 * anywhere a holding period decides something, as it does for capital gains
 * tax: zero days would confidently apply the short-term rate to a position
 * that may well have been held for years. So the flag travels with the trade
 * and the tax calculator refuses to rate an unmatched disposal rather than
 * guessing from a date it invented.
 */
final class ClosedTrade
{
    public function __construct(
        public readonly string $symbol,
        public readonly Carbon $openedOn,
        public readonly Carbon $closedOn,
        public readonly float $quantity,
        public readonly float $costBasis,
        public readonly float $proceeds,
        public readonly float $fees,
        public readonly float $gain,
        /**
         * Whether `openedOn` came from a matched purchase lot rather than from
         * the sell-date fallback. Defaults true so a hand-built trade behaves
         * as before; only the FIFO replay sets it false.
         */
        public readonly bool $acquisitionDateKnown = true,
    ) {}

    public function gainPercent(): ?float
    {
        return $this->costBasis > 0.0 ? round($this->gain / $this->costBasis * 100, 2) : null;
    }

    public function daysHeld(): int
    {
        return (int) $this->openedOn->diffInDays($this->closedOn);
    }

    /**
     * Days held, or null where the acquisition date was never matched.
     *
     * Use this anywhere the holding period decides an outcome. `daysHeld()`
     * keeps returning a number because the averages that consume it are
     * tolerant of one imported position; a tax band is not.
     */
    public function holdingPeriodDays(): ?int
    {
        return $this->acquisitionDateKnown ? $this->daysHeld() : null;
    }

    public function isWin(): bool
    {
        return $this->gain > 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'opened_on' => $this->openedOn->toDateString(),
            'closed_on' => $this->closedOn->toDateString(),
            'quantity' => round($this->quantity, 2),
            'cost_basis' => round($this->costBasis, 2),
            'proceeds' => round($this->proceeds, 2),
            'fees' => round($this->fees, 2),
            'gain' => round($this->gain, 2),
            'gain_percent' => $this->gainPercent(),
            'days_held' => $this->daysHeld(),
            'acquisition_date_known' => $this->acquisitionDateKnown,
            'is_win' => $this->isWin(),
        ];
    }
}
