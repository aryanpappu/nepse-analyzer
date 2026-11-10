<?php

declare(strict_types=1);

namespace App\Services\Playbook\Backtest;

/**
 * One dated bar, as a plain value object.
 *
 * Deliberately not an Eloquent model and deliberately a `Y-m-d` string rather
 * than a Carbon: the whole simulation core is a pure function over arrays of
 * these, which is what lets it be unit-tested without a database (this suite
 * runs against the real MySQL connection — see CLAUDE.md — so a test that
 * needs rows is a test that cannot be written here).
 *
 * `open`, `high` and `low` are nullable because older scraped rows carry only
 * a close. Every reader goes through the `*OrClose()` accessors so a partial
 * bar degrades to a single-price bar instead of poisoning a comparison with a
 * null.
 */
final class Bar
{
    public function __construct(
        public readonly string $date,
        public readonly float $close,
        public readonly ?float $open = null,
        public readonly ?float $high = null,
        public readonly ?float $low = null,
        public readonly ?float $volume = null,
    ) {}

    public function openOrClose(): float
    {
        return $this->open !== null && $this->open > 0.0 ? $this->open : $this->close;
    }

    public function highOrClose(): float
    {
        return $this->high !== null && $this->high > 0.0 ? $this->high : $this->close;
    }

    public function lowOrClose(): float
    {
        return $this->low !== null && $this->low > 0.0 ? $this->low : $this->close;
    }
}
