<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * The arithmetic a human otherwise does in a scratch script: money in, money
 * out, net injected, and whether the statement and the trade ledger agree
 * about how much capital was put to work.
 *
 * Reported after every commit, because a cash import that does not reconcile
 * against the trades is the one failure mode that matters. If the statement
 * says 1,093,760 went in and the trade ledger only accounts for 900,000 of
 * spending, either a statement page is missing or trades are — and every
 * money-weighted figure downstream (XIRR, vs-FD, vs-index, attribution) is
 * wrong until that is resolved.
 *
 * Pure: arrays and floats in, arrays out. No database, no clock.
 */
final class CashStatementReconciler
{
    /**
     * Rupees of disagreement treated as agreement. Brokerage statements round
     * to the paisa and a rupee of drift across two dozen movements is
     * rounding, not a missing page.
     */
    public const TOLERANCE = 1.0;

    /**
     * Money in, money out and net injected, over the rows that will actually
     * be committed.
     *
     * Excluded rows are counted and totalled separately rather than dropped:
     * "16,000 of failed top-ups were excluded" is the line that stops a user
     * concluding the importer lost two deposits.
     *
     * @param  list<array<string, mixed>>  $rows  Staged rows, any status.
     * @return array{
     *     money_in: float,
     *     money_out: float,
     *     net_injected: float,
     *     deposits: int,
     *     withdrawals: int,
     *     movements: int,
     *     first_movement_on: ?string,
     *     last_movement_on: ?string,
     *     excluded: array<string, array{count: int, amount: float}>,
     *     capital_in: ?float,
     *     capital_out: ?float,
     *     net_capital: ?float
     * }
     */
    public static function totals(array $rows): array
    {
        $moneyIn = 0.0;
        $moneyOut = 0.0;
        $deposits = 0;
        $withdrawals = 0;
        $dates = [];
        $excluded = [];

        /*
         * Contributed capital, tracked apart from the gross movement.
         *
         * A sell settlement credits the account, but it is not new money — it
         * is a share turning back into cash. Counting it as a deposit roughly
         * doubles the figure every money-weighted calculation in the app rests
         * on. Only formats that label their rows can tell the difference, so
         * this stays null for the ones that cannot rather than reporting a
         * number that looks authoritative and is wrong.
         */
        $capitalIn = 0.0;
        $capitalOut = 0.0;
        $kindsKnown = false;

        foreach ($rows as $row) {
            $amount = $row['amount'] === null ? null : (float) $row['amount'];
            $direction = $row['direction'] ?? null;

            /*
             * Whether the *format* labels its rows is a property of the file,
             * not of which rows happen to be included. Reading it after the
             * include check meant that a statement whose rows were all
             * excluded as duplicates reported "this format cannot tell
             * deposits from settlements" — which is false, and alarming.
             */
            if (($row['kind'] ?? null) !== null) {
                $kindsKnown = true;
            }

            if (! (bool) ($row['include'] ?? false)) {
                $status = (string) ($row['status'] ?? CashStatementParser::ROW_NEEDS_REVIEW);
                $excluded[$status] ??= ['count' => 0, 'amount' => 0.0];
                $excluded[$status]['count']++;
                $excluded[$status]['amount'] = round($excluded[$status]['amount'] + ($amount ?? 0.0), 2);

                continue;
            }

            if ($amount === null || $direction === null) {
                continue;
            }

            $kind = $row['kind'] ?? null;
            $isCapital = $kind !== null && in_array($kind, CashStatementParser::CAPITAL_KINDS, true);

            if ($direction === CashStatementParser::DIRECTION_OUT) {
                $moneyOut += $amount;
                $withdrawals++;

                if ($isCapital) {
                    $capitalOut += $amount;
                }
            } else {
                $moneyIn += $amount;
                $deposits++;

                if ($isCapital) {
                    $capitalIn += $amount;
                }
            }

            if (($row['occurred_on'] ?? null) !== null) {
                $dates[] = (string) $row['occurred_on'];
            }
        }

        sort($dates);

        return [
            'money_in' => round($moneyIn, 2),
            'money_out' => round($moneyOut, 2),
            'net_injected' => round($moneyIn - $moneyOut, 2),
            'deposits' => $deposits,
            'withdrawals' => $withdrawals,
            'movements' => $deposits + $withdrawals,
            'first_movement_on' => $dates === [] ? null : $dates[0],
            'last_movement_on' => $dates === [] ? null : $dates[count($dates) - 1],
            'excluded' => $excluded,
            // Null, not zero, when the format cannot label its rows.
            'capital_in' => $kindsKnown ? round($capitalIn, 2) : null,
            'capital_out' => $kindsKnown ? round($capitalOut, 2) : null,
            'net_capital' => $kindsKnown ? round($capitalIn - $capitalOut, 2) : null,
        ];
    }

    /**
     * Compare the statement's net injection against what the portfolio says.
     *
     * @param  array<string, mixed>  $totals  From {@see totals()}.
     * @param  float|null  $ledgerImpliedCapital  Net cash the trade ledger consumed —
     *                                            buys plus fees, less sale proceeds and dividends. Null when
     *                                            there are no trades to compare against.
     * @param  float|null  $closingNetWorth  Holdings marked to market plus residual cash
     *                                       and settled placements. Null when a holding cannot be priced —
     *                                       profit then reports null, never zero.
     * @return array<string, mixed>
     */
    public static function reconcile(
        array $totals,
        ?float $ledgerImpliedCapital = null,
        ?float $closingNetWorth = null,
    ): array {
        $net = (float) $totals['net_injected'];

        $difference = $ledgerImpliedCapital === null ? null : round($net - $ledgerImpliedCapital, 2);

        $profit = $closingNetWorth === null ? null : round($closingNetWorth - $net, 2);

        // A return needs capital to divide by. With none, there is no return —
        // and a 0% would read as a flat portfolio rather than an absent one.
        $simpleReturnPercent = $profit !== null && $net > 0.0
            ? round($profit / $net * 100, 2)
            : null;

        return [
            'money_in' => $totals['money_in'],
            'money_out' => $totals['money_out'],
            'net_injected' => $net,
            'deposits' => $totals['deposits'],
            'withdrawals' => $totals['withdrawals'],
            'movements' => $totals['movements'],
            'first_movement_on' => $totals['first_movement_on'],
            'last_movement_on' => $totals['last_movement_on'],
            'excluded' => $totals['excluded'],

            'ledger_implied_capital' => $ledgerImpliedCapital === null ? null : round($ledgerImpliedCapital, 2),
            'difference' => $difference,
            'agrees' => $difference === null ? null : abs($difference) <= self::TOLERANCE,

            'closing_net_worth' => $closingNetWorth === null ? null : round($closingNetWorth, 2),
            'profit' => $profit,
            'simple_return_percent' => $simpleReturnPercent,

            'note' => self::note($net, $difference, $profit, $simpleReturnPercent),
        ];
    }

    /**
     * The plain-English version, so the command output and the API response
     * cannot describe the same numbers differently.
     */
    private static function note(float $net, ?float $difference, ?float $profit, ?float $returnPercent): string
    {
        $lines = [sprintf('Net capital injected: NPR %s.', number_format($net, 2))];

        if ($difference === null) {
            $lines[] = 'There are no trades to reconcile against yet.';
        } elseif (abs($difference) <= self::TOLERANCE) {
            $lines[] = 'The trade ledger accounts for that capital to within a rupee.';
        } elseif ($difference > 0) {
            $lines[] = sprintf(
                'NPR %s more went in than the trades account for — either that much is still '
                .'sitting as cash, or trades are missing.',
                number_format(abs($difference), 2),
            );
        } else {
            $lines[] = sprintf(
                'The trades consumed NPR %s more than the statement shows going in — a statement '
                .'page is probably missing.',
                number_format(abs($difference), 2),
            );
        }

        if ($profit === null) {
            $lines[] = 'Profit is not reported: at least one holding has no price, so a total would be a guess.';
        } elseif ($returnPercent === null) {
            $lines[] = sprintf('Profit against that capital: NPR %s.', number_format($profit, 2));
        } else {
            $lines[] = sprintf(
                'Profit: NPR %s, %s%s simple on the capital injected.',
                number_format($profit, 2),
                $returnPercent >= 0 ? '+' : '',
                number_format($returnPercent, 2),
            );
        }

        return implode(' ', $lines);
    }
}
