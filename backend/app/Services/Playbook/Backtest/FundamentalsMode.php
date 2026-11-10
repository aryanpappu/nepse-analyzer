<?php

declare(strict_types=1);

namespace App\Services\Playbook\Backtest;

/**
 * What the replay is allowed to know about a company's filed numbers.
 *
 * This is the one place the honesty of the whole backtest is decided, so it is
 * an explicit enum rather than a boolean flag.
 *
 * `stock_fundamentals` carries no usable as-at date on historical rows
 * (`reported_on` has been NULL across the table, and `fiscal_period` is a
 * non-uniform BS string), and the stored `pe_ratio`/`pb_ratio` were derived
 * from the price at *scrape* time. Feeding those into a 2023 replay tells the
 * engine what the company would report in 2026 and what its multiple would be
 * at a 2026 price — the largest single look-ahead channel available in this
 * dataset, and one that flatters `long_term` most because fundamentals and
 * valuation are 65% of its weight.
 *
 * So `None` is the default and `Latest` must be asked for out loud.
 */
enum FundamentalsMode: string
{
    /**
     * No fundamentals at all. The engine's own missing-bucket rule applies:
     * `fundamentals` and `valuation` return null and their weight is
     * redistributed over trend, momentum and volume. The result is an honest
     * measurement of a *technical-only variant* of each profile — which must
     * be said in the report, because for `long_term` that is 65% of the weight
     * moving somewhere else.
     */
    case None = 'none';

    /**
     * Only rows whose `reported_on` is populated and falls on or before the
     * signal date. Look-ahead free and the right answer once the roadmap's
     * `reported_on` work lands; today it yields nothing for most scrips, which
     * the coverage block reports rather than hiding.
     */
    case Dated = 'dated';

    /**
     * The latest filed row, whatever its date. Deliberately contaminated:
     * available only behind an explicit `--allow-lookahead`, and every figure
     * produced under it is flagged. Useful for exactly one thing — measuring
     * how much of a grade's apparent edge is hindsight.
     */
    case Latest = 'latest';

    public function isLookAhead(): bool
    {
        return $this === self::Latest;
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'excluded (technical-only replay)',
            self::Dated => 'dated rows only (reported_on <= signal date)',
            self::Latest => 'latest filed row — LOOK-AHEAD CONTAMINATED',
        };
    }
}
