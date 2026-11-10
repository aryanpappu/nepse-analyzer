<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

/**
 * Why the portfolio returned what it returned — pure arithmetic over plain
 * arrays, no Eloquent, no config, no clock, in the same spirit as
 * {@see IndexReturnCalculator} and {@see InterestAccrualCalculator}.
 *
 * ## The decomposition, and why it reconciles
 *
 * Every figure rests on one definition of capital at risk, borrowed verbatim
 * from `PerformanceService::periodReturn()`'s `simple_return_percent` and
 * `IndexReturnCalculator::cashFlowAdjustedReturn()` so attribution and
 * performance cannot disagree:
 *
 *     base_i   = opening_i + max(0, contribution_i)
 *     profit_i = closing_i - opening_i - contribution_i
 *     B        = Σ base_i
 *
 * where `contribution_i` is money put into that scrip during the window
 * (positive for a buy, negative for a sale or dividend — the inverse of
 * `PerformanceService::cashFlow()`, matching how a period reports
 * `net_contribution`).
 *
 * From those:
 *
 *     weight_i       = base_i / B
 *     return_i       = profit_i / base_i        (the scrip's own return)
 *     contribution_i = profit_i / B  =  weight_i × return_i
 *     R              = Σ profit_i / B  =  Σ contribution_i
 *
 * The last line is the invariant: **the per-holding contributions sum to the
 * portfolio's return over the same window, exactly.** It holds algebraically,
 * not approximately, because both sides are the same sum over the same
 * denominator. That is what lets the answer to "is the shortfall two bad
 * scrips or the whole book" be trusted.
 *
 * Note which total it reconciles against: the cash-flow-adjusted *simple*
 * return over the window, not XIRR. A money-weighted annualised rate is the
 * root of a polynomial and is not additive across holdings — no honest
 * decomposition of it into per-holding parts exists, so none is offered.
 *
 * ## Where it declines to answer
 *
 * - A holding with no mark at either boundary is **excluded and named**. It is
 *   never valued at cost: cost-as-value silently reports 0% on it and drags
 *   the whole decomposition toward zero. The excluded value is reported so a
 *   caller can see how much of the book the answer covers.
 * - `base_i = 0` (a position with no opening value that only returned money,
 *   e.g. dividends on a scrip bought before the window and sold within it)
 *   has a real profit but no capital base, so `return_i` and `weight_i` are
 *   `null` while `contribution_i` is still reported — the invariant survives,
 *   and no fabricated percentage appears.
 * - `B = 0` — no capital at risk anywhere — returns `null` for the total,
 *   never 0.0.
 */
final class ReturnAttributionCalculator
{
    /** Reconciliation tolerance in percentage points, for float rounding only. */
    private const TOLERANCE = 0.005;

    /**
     * Per-holding contribution to the window's return.
     *
     * @param  list<array{
     *     symbol: string,
     *     sector?: ?string,
     *     sector_slug?: ?string,
     *     opening_value: ?float,
     *     closing_value: ?float,
     *     net_contribution: float,
     *     exclude_reason?: ?string,
     * }>  $positions  `null` on either boundary value means "no mark", not zero.
     * @return array{
     *     holdings: list<array<string, mixed>>,
     *     total: array{capital_base: ?float, profit: ?float, return_percent: ?float, holdings: int},
     *     excluded: list<array{symbol: string, reason: string}>,
     *     excluded_count: int,
     *     reconciles: bool,
     *     reconciliation_error_percent: float
     * }
     */
    public function contributions(array $positions): array
    {
        $priced = [];
        $excluded = [];

        foreach ($positions as $position) {
            $reason = $position['exclude_reason'] ?? null;

            if ($reason === null && $position['opening_value'] === null) {
                $reason = 'No price or NAV on or before the window start, so its opening value is unknown.';
            }

            if ($reason === null && $position['closing_value'] === null) {
                $reason = 'No price or NAV on or before the window end, so its closing value is unknown. '
                    .'Counting it at cost would silently report a 0% return on it.';
            }

            if ($reason !== null) {
                $excluded[] = ['symbol' => $position['symbol'], 'reason' => $reason];

                continue;
            }

            $priced[] = $position;
        }

        $totalBase = 0.0;
        $totalProfit = 0.0;
        $measured = [];

        foreach ($priced as $position) {
            $opening = (float) $position['opening_value'];
            $closing = (float) $position['closing_value'];
            $contribution = (float) $position['net_contribution'];

            $base = $this->capitalBase($opening, $contribution);
            $profit = $closing - $opening - $contribution;

            $totalBase += $base;
            $totalProfit += $profit;

            $measured[] = [
                'symbol' => $position['symbol'],
                'sector' => $position['sector'] ?? null,
                'sector_slug' => $position['sector_slug'] ?? null,
                'opening_value' => round($opening, 2),
                'closing_value' => round($closing, 2),
                'net_contribution' => round($contribution, 2),
                'capital_base' => round($base, 2),
                'profit' => round($profit, 2),
                'base' => $base,
                'raw_profit' => $profit,
            ];
        }

        $holdings = [];
        $contributionSum = 0.0;

        foreach ($measured as $row) {
            $base = $row['base'];
            $profit = $row['raw_profit'];
            unset($row['base'], $row['raw_profit']);

            $contributionPercent = $totalBase > 0.0 ? $profit / $totalBase * 100 : null;
            $contributionSum += $contributionPercent ?? 0.0;

            $holdings[] = $row + [
                // weight × return = contribution, by construction.
                'weight_percent' => ($base > 0.0 && $totalBase > 0.0) ? round($base / $totalBase * 100, 4) : null,
                'return_percent' => $base > 0.0 ? round($profit / $base * 100, 4) : null,
                'contribution_percent' => $contributionPercent === null ? null : round($contributionPercent, 4),
            ];
        }

        usort(
            $holdings,
            static fn (array $a, array $b): int => ($b['contribution_percent'] ?? 0.0) <=> ($a['contribution_percent'] ?? 0.0),
        );

        $totalReturn = $totalBase > 0.0 ? $totalProfit / $totalBase * 100 : null;
        $error = $totalReturn === null ? 0.0 : $contributionSum - $totalReturn;

        return [
            'holdings' => $holdings,
            'total' => [
                'capital_base' => $totalBase > 0.0 ? round($totalBase, 2) : null,
                'profit' => $totalBase > 0.0 ? round($totalProfit, 2) : null,
                'return_percent' => $totalReturn === null ? null : round($totalReturn, 4),
                'holdings' => count($holdings),
            ],
            'excluded' => $excluded,
            'excluded_count' => count($excluded),
            'reconciles' => abs($error) < self::TOLERANCE,
            'reconciliation_error_percent' => round($error, 6),
        ];
    }

    /**
     * Each sector's own return against its sub-index's return over the same
     * window — like-for-like, and needing no benchmark weights.
     *
     * A sector with no sub-index (`index_return_percent` null) reports the
     * reason it has none and leaves the gap `null`. Comparing a debenture
     * book against the banking sub-index because one happened to be
     * available would be worse than saying nothing.
     *
     * @param  list<array{
     *     sector: string,
     *     sector_slug: ?string,
     *     opening_value: float,
     *     closing_value: float,
     *     net_contribution: float,
     *     index_slug?: ?string,
     *     index_name?: ?string,
     *     index_return_percent?: ?float,
     *     index_coverage_percent?: ?float,
     *     index_note?: ?string,
     *     holdings?: list<string>,
     *     excluded_symbols?: list<string>,
     * }>  $sectors
     * @return list<array<string, mixed>>
     */
    public function sectorSelection(array $sectors): array
    {
        $rows = [];

        foreach ($sectors as $sector) {
            $base = $this->capitalBase((float) $sector['opening_value'], (float) $sector['net_contribution']);
            $profit = (float) $sector['closing_value'] - (float) $sector['opening_value'] - (float) $sector['net_contribution'];
            $portfolioReturn = $base > 0.0 ? round($profit / $base * 100, 4) : null;
            $indexReturn = $sector['index_return_percent'] ?? null;

            $gap = ($portfolioReturn === null || $indexReturn === null)
                ? null
                : round($portfolioReturn - $indexReturn, 4);

            $rows[] = [
                'sector' => $sector['sector'],
                'sector_slug' => $sector['sector_slug'],
                'holdings' => $sector['holdings'] ?? [],
                'excluded_symbols' => $sector['excluded_symbols'] ?? [],
                'opening_value' => round((float) $sector['opening_value'], 2),
                'closing_value' => round((float) $sector['closing_value'], 2),
                'net_contribution' => round((float) $sector['net_contribution'], 2),
                'capital_base' => round($base, 2),
                'profit' => round($profit, 2),
                'portfolio_return_percent' => $portfolioReturn,
                'index_slug' => $sector['index_slug'] ?? null,
                'index_name' => $sector['index_name'] ?? null,
                'index_return_percent' => $indexReturn,
                'index_coverage_percent' => $sector['index_coverage_percent'] ?? null,
                'selection_gap_percent' => $gap,
                'beat_sub_index' => $gap === null ? null : $gap > 0.0,
                'index_note' => $sector['index_note'] ?? null,
            ];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => $b['capital_base'] <=> $a['capital_base'],
        );

        return $rows;
    }

    /**
     * Capital actually at risk over the window: what was there at the start,
     * plus money added during it. Money withdrawn is not subtracted — it was
     * at risk until it left.
     */
    private function capitalBase(float $openingValue, float $netContribution): float
    {
        return $openingValue + max(0.0, $netContribution);
    }
}
