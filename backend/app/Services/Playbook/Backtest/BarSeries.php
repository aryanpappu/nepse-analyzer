<?php

declare(strict_types=1);

namespace App\Services\Playbook\Backtest;

use App\Services\Playbook\PlaybookInputFactory;

/**
 * An ordered, immutable run of bars for one scrip — and the single mechanism
 * that makes look-ahead bias structurally impossible rather than merely
 * intended.
 *
 * The historical replay never reads a bar array directly. It holds one
 * `BarSeries` per scrip and asks for `upTo($date)` before scoring and
 * `after($date)` before simulating, so the scoring half of the loop is handed
 * an object that physically does not contain a single future bar. Getting the
 * cut wrong is then one bug in one place with one test on it, instead of a
 * bias distributed across every window aggregate.
 *
 * Pure: no Carbon, no clock, no database. Dates are `Y-m-d`, which sorts and
 * compares lexicographically, so the whole class is string comparison.
 */
final class BarSeries
{
    /** @var list<Bar> Ascending by date, one bar per date. */
    private readonly array $bars;

    /**
     * @param  list<Bar>  $bars  Order does not matter; duplicates on a date keep the last seen.
     */
    public function __construct(array $bars)
    {
        $byDate = [];

        foreach ($bars as $bar) {
            $byDate[$bar->date] = $bar;
        }

        ksort($byDate);

        $this->bars = array_values($byDate);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @return list<Bar>
     */
    public function bars(): array
    {
        return $this->bars;
    }

    public function count(): int
    {
        return count($this->bars);
    }

    public function isEmpty(): bool
    {
        return $this->bars === [];
    }

    public function firstDate(): ?string
    {
        return $this->bars === [] ? null : $this->bars[0]->date;
    }

    public function lastDate(): ?string
    {
        return $this->bars === [] ? null : $this->bars[count($this->bars) - 1]->date;
    }

    /**
     * Everything on or before $date. The scoring half of the replay only ever
     * sees the result of this call.
     */
    public function upTo(string $date): self
    {
        return $this->slice(static fn (Bar $bar): bool => $bar->date <= $date);
    }

    /**
     * Everything strictly after $date — the forward path the trade simulator
     * walks. Strict, because the signal is taken on $date's close: that bar's
     * own high and low are already spent by the time the order could be placed.
     */
    public function after(string $date): self
    {
        return $this->slice(static fn (Bar $bar): bool => $bar->date > $date);
    }

    public function barOn(string $date): ?Bar
    {
        foreach ($this->bars as $bar) {
            if ($bar->date === $date) {
                return $bar;
            }

            if ($bar->date > $date) {
                return null;
            }
        }

        return null;
    }

    /**
     * Highest traded price in [$from, $to] inclusive, falling back to the
     * close on rows with no high — the same `coalesce(high, close)` the live
     * {@see PlaybookInputFactory} uses, so the replay
     * feeds the engine the number it would have been fed at the time.
     */
    public function highBetween(string $from, string $to): ?float
    {
        $high = null;

        foreach ($this->bars as $bar) {
            if ($bar->date < $from || $bar->date > $to) {
                continue;
            }

            $value = $bar->highOrClose();
            $high = $high === null ? $value : max($high, $value);
        }

        return $high;
    }

    public function lowBetween(string $from, string $to): ?float
    {
        $low = null;

        foreach ($this->bars as $bar) {
            if ($bar->date < $from || $bar->date > $to) {
                continue;
            }

            $value = $bar->lowOrClose();
            $low = $low === null ? $value : min($low, $value);
        }

        return $low;
    }

    /**
     * @param  callable(Bar): bool  $keep
     */
    private function slice(callable $keep): self
    {
        $kept = [];

        foreach ($this->bars as $bar) {
            if ($keep($bar)) {
                $kept[] = $bar;
            }
        }

        return $kept === $this->bars ? $this : new self($kept);
    }
}
