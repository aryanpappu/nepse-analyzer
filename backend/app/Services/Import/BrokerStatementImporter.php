<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\CashTransaction;
use App\Models\Portfolio;
use App\Models\Stock;
use App\Services\Portfolio\CashLedger;
use App\Services\Portfolio\HoldingCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Replace a MeroShare-derived position snapshot with the broker's real history.
 *
 * The MeroShare imports describe what is held *now*, so the ledger ended up
 * holding one synthetic buy per open position and not a single sell — no
 * realised gains, no round trips for FIFO to match, and no cash settlements.
 * The broker statement is the actual history, and importing it makes every
 * money-weighted figure real for the first time.
 *
 * ## Ordering, and why it matters
 *
 * `CashLedger` validates against the balance at the moment of writing, so rows
 * must be replayed in an order that reflects reality. Two rules:
 *
 *  1. Chronological. The export is newest-first in places, and replaying a
 *     withdrawal before the deposit that funded it fails.
 *  2. Within one day, money in before money out. In a fictional example,
 *     printed order dips to -2,000.00; crediting first leaves only -250.00
 *     of actual exposure.
 *
 * Brokers may extend credit between settlement dates, so the import passes
 * `allowOverdraft` and reports historical debit exposure.
 *
 * ## Self-checking
 *
 * The statement prints its own closing balance. This replays to that figure
 * and refuses to commit if it disagrees, so a parser bug surfaces as a failed
 * import rather than a plausible wrong number. The whole operation runs in one
 * transaction, so a mismatch leaves the portfolio exactly as it was.
 */
final class BrokerStatementImporter
{
    public function __construct(
        private readonly BrokerStatementParser $parser,
        private readonly CashLedger $ledger,
        private readonly HoldingCalculator $holdings,
    ) {}

    /**
     * Parse and summarise without writing anything.
     *
     * @return array<string, mixed>
     */
    public function preview(string $path): array
    {
        $parsed = $this->parser->parseFile($path);

        return $this->summarise($parsed) + ['warnings' => $parsed['warnings']];
    }

    /**
     * @return array<string, mixed>
     */
    public function import(Portfolio $portfolio, string $path, string $account = CashTransaction::ACCOUNT_BANK): array
    {
        $parsed = $this->parser->parseFile($path);
        $summary = $this->summarise($parsed);

        // Checked before anything is touched: an unreconciled statement means
        // the parser misread it, and importing would be worse than not.
        if ($summary['reconciles'] !== true) {
            throw new RuntimeException(sprintf(
                'The statement does not reconcile: it prints a closing balance of NPR %s but its rows replay to NPR %s. Nothing was changed.',
                number_format((float) $summary['statement_closing_balance'], 2),
                number_format((float) $summary['replayed_closing_balance'], 2),
            ));
        }

        $newSymbols = $this->unknownSymbols($parsed['trades']);

        return DB::transaction(function () use ($portfolio, $parsed, $summary, $account, $newSymbols): array {
            $replaced = $this->clearExisting($portfolio, $summary['symbols']);

            $trades = $this->recordTrades($portfolio, $parsed['trades']);

            // Replay every surviving transaction so preserved holdings —
            // IPO allotments, mutual funds, bonus issues — come back.
            $rebuilt = $this->holdings->rebuild($portfolio);
            $cash = $this->recordCash($portfolio, $parsed['rows'], $account);

            $portfolio->refresh();
            $finalCash = round((float) $portfolio->{CashTransaction::balanceColumn($account)}, 2);
            $expected = (float) $summary['statement_closing_balance'];

            // The second half of the self-check: the ledger we just wrote must
            // land on the broker's own figure.
            if (abs($finalCash - $expected) >= 0.01) {
                throw new RuntimeException(sprintf(
                    'Imported cash came to NPR %s but the statement closes at NPR %s. Rolled back.',
                    number_format($finalCash, 2),
                    number_format($expected, 2),
                ));
            }

            return $summary + [
                'replaced_transactions' => $replaced['transactions'],
                'replaced_cash_rows' => $replaced['cash'],
                'preserved_symbols' => $replaced['preserved_symbols'],
                'positions_rebuilt' => $rebuilt,
                'trades_recorded' => $trades,
                'cash_rows_recorded' => $cash,
                'final_cash_balance' => $finalCash,
                'cash_account' => $account,
                'symbols_created' => $newSymbols,
                'warnings' => $parsed['warnings'],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function summarise(array $parsed): array
    {
        $totals = [];

        foreach ($parsed['rows'] as $row) {
            $totals[$row['kind']] ??= ['rows' => 0, 'amount' => 0.0];
            $totals[$row['kind']]['rows']++;
            $totals[$row['kind']]['amount'] = round($totals[$row['kind']]['amount'] + $row['amount'], 2);
        }

        $ordered = $this->ordered($parsed['rows']);
        $replayed = $this->parser->replayBalance($ordered, $parsed['opening_balance']);
        $closing = $parsed['closing_balance'];

        return [
            'statement_closing_balance' => $closing,
            'replayed_closing_balance' => $replayed,
            'reconciles' => $closing !== null && abs($replayed - $closing) < 0.01,
            'opening_balance' => $parsed['opening_balance'],
            'totals' => $totals,
            'trade_count' => count($parsed['trades']),
            'buy_count' => count(array_filter($parsed['trades'], static fn (array $t): bool => $t['side'] === 'buy')),
            'sell_count' => count(array_filter($parsed['trades'], static fn (array $t): bool => $t['side'] === 'sell')),
            'symbols' => array_values(array_unique(array_column($parsed['trades'], 'symbol'))),
            'worst_debit_exposure' => $this->worstExposure($ordered, $parsed['opening_balance']),
            'opening_parcels' => $this->openingParcels($parsed['trades']),
        ];
    }

    /**
     * Chronological, and within a day money in before money out.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function ordered(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => [
            $a['occurred_on'], $a['is_credit'] ? 0 : 1, $a['line'],
        ] <=> [
            $b['occurred_on'], $b['is_credit'] ? 0 : 1, $b['line'],
        ]);

        return $rows;
    }

    /**
     * The deepest the account actually went into debit — reported rather than
     * hidden, because it is the broker's credit line and the user should see it.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function worstExposure(array $rows, float $opening): float
    {
        $balance = $opening;
        $worst = 0.0;

        foreach ($rows as $row) {
            $balance = round($balance + ($row['is_credit'] ? $row['amount'] : -$row['amount']), 2);
            $worst = min($worst, $balance);
        }

        return $worst;
    }

    /**
     * Clear only what this statement is entitled to replace.
     *
     * **Scoped to the scrips the statement accounts for**, and this is the
     * whole point. A broker statement is a complete record of *broker*
     * activity and nothing else: an IPO allotment, a mutual-fund subscription
     * and a bonus issue all arrive through CDSC and appear in it nowhere.
     * Clearing the portfolio wholesale therefore deletes every holding the
     * broker never touched — for example, a fictional set of 4 holdings with
     * 12,000.00 of cost basis.
     *
     * The three sources are complementary, not competing, and each is
     * authoritative for what it actually knows:
     *
     *   MeroShare portfolio CSV  quantity held, every channel
     *   MeroShare WACC CSV       cost basis
     *   broker statement         trade history, dates, cash, realised gains
     *
     * So the rule is: the statement replaces transactions for symbols it
     * settles, and has **no opinion** about anything else. No opinion must
     * never mean deletion.
     *
     * Cash is different — the statement is the only account of it, so the cash
     * ledger is replaced in full. Holdings are rebuilt by `HoldingCalculator`
     * from the surviving plus incoming transactions, since positions are a
     * function of the ledger.
     *
     * @param  list<string>  $symbols  Scrips this statement settles.
     * @return array{transactions: int, cash: int, preserved_symbols: list<string>}
     */
    private function clearExisting(Portfolio $portfolio, array $symbols): array
    {
        $stockIds = Stock::whereIn('symbol', $symbols)->pluck('id');

        $transactions = DB::table('portfolio_transactions')
            ->where('portfolio_id', $portfolio->id)
            ->whereIn('stock_id', $stockIds)
            ->delete();

        // The statement is the only record of the broker cash account, so this
        // side is authoritative in full.
        $cash = DB::table('cash_transactions')->where('portfolio_id', $portfolio->id)->delete();

        $preserved = DB::table('portfolio_transactions')
            ->join('stocks', 'stocks.id', '=', 'portfolio_transactions.stock_id')
            ->where('portfolio_transactions.portfolio_id', $portfolio->id)
            ->distinct()
            ->pluck('stocks.symbol')
            ->map(static fn (mixed $v): string => (string) $v)
            ->values()
            ->all();

        // Positions are a function of the ledger: drop them and let
        // HoldingCalculator rebuild from every transaction that remains,
        // preserved and incoming alike.
        DB::table('portfolio_holdings')->where('portfolio_id', $portfolio->id)->delete();

        $portfolio->update(['cash_in_hand' => 0, 'bank_balance' => 0]);

        return [
            'transactions' => $transactions,
            'cash' => $cash,
            'preserved_symbols' => $preserved,
        ];
    }

    /**
     * Positions the statement disposes of but never acquired.
     *
     * The statement opens in 2022 with a *sale* of BHDC, and six more scrips
     * are sold in quantities the statement never bought: BHPL, HRL, SMHL and
     * CLI outright, KBL 47 against 26 bought, GCIL 51 against 40. Those are
     * precisely the shares a broker statement cannot explain — IPO allotments
     * (10 units is the standard minimum lot) and bonus issues, both of which
     * arrive through CDSC.
     *
     * They are seeded as opening parcels at **zero recorded cost and an unknown
     * channel**, never at the sale price. Using the sale price would report a
     * tidy zero gain on a parcel that probably quadrupled; guessing par would
     * be inventing a number. Zero-cost keeps the units real while leaving the
     * cost visibly unrecorded, and the channel stays `unknown` so the profit
     * split reports them apart from actual trading rather than crediting them
     * to stock picking. The command names every one so they can be classified
     * and priced by hand.
     *
     * @param  list<array<string, mixed>>  $trades
     * @return list<array<string, mixed>>
     */
    private function openingParcels(array $trades): array
    {
        $held = [];
        $parcels = [];

        foreach ($this->tradeOrder($trades) as $trade) {
            $symbol = $trade['symbol'];
            $held[$symbol] ??= 0.0;

            if ($trade['side'] === 'buy') {
                $held[$symbol] += $trade['quantity'];

                continue;
            }

            $short = round($trade['quantity'] - $held[$symbol], 2);

            if ($short > 0.0) {
                $parcels[] = [
                    'symbol' => $symbol,
                    'quantity' => $short,
                    'transacted_on' => $trade['transacted_on'],
                    'sold_at' => $trade['price'],
                ];

                $held[$symbol] += $short;
            }

            $held[$symbol] = round($held[$symbol] - $trade['quantity'], 2);
        }

        return $parcels;
    }

    /**
     * Chronological, buys before sells on the same day — a scrip bought and
     * sold on one date must not look like an oversell.
     *
     * @param  list<array<string, mixed>>  $trades
     * @return list<array<string, mixed>>
     */
    private function tradeOrder(array $trades): array
    {
        usort($trades, static fn (array $a, array $b): int => [
            $a['transacted_on'], $a['side'] === 'buy' ? 0 : 1,
        ] <=> [
            $b['transacted_on'], $b['side'] === 'buy' ? 0 : 1,
        ]);

        return $trades;
    }

    /**
     * @param  list<array<string, mixed>>  $trades
     */
    private function recordTrades(Portfolio $portfolio, array $trades): int
    {
        $recorded = 0;

        // Seed what the statement sells but never bought, dated the day before
        // the disposal so FIFO has a lot to consume.
        foreach ($this->openingParcels($trades) as $parcel) {
            $this->holdings->record($portfolio, $this->stock($parcel['symbol']), [
                'type' => 'buy',
                'quantity' => $parcel['quantity'],
                'price' => 0,
                'transacted_on' => Carbon::parse($parcel['transacted_on'])->subDay()->toDateString(),
                'notes' => 'Held before this statement — cost not recorded. Set the channel and cost to include it in gains.',
            ]);

            $recorded++;
        }

        $trades = $this->tradeOrder($trades);

        foreach ($trades as $trade) {
            // A scrip traded years ago may no longer be in the stock table; a
            // stub keeps the trade importable and is flagged as unclassified
            // rather than guessed at.
            $this->holdings->record($portfolio, $this->stock($trade['symbol']), [
                'type' => $trade['side'],
                'quantity' => $trade['quantity'],
                'price' => $trade['price'],
                'transacted_on' => $trade['transacted_on'],
                'notes' => 'Broker statement '.$trade['reference'],
            ]);

            $recorded++;
        }

        return $recorded;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function recordCash(Portfolio $portfolio, array $rows, string $account): int
    {
        $recorded = 0;

        foreach ($this->ordered($rows) as $row) {
            $type = match ($row['kind']) {
                BrokerStatementParser::KIND_DEPOSIT => 'deposit',
                BrokerStatementParser::KIND_WITHDRAWAL => 'withdrawal',
                BrokerStatementParser::KIND_BUY => 'trade_debit',
                BrokerStatementParser::KIND_SELL => 'trade_credit',
                default => throw new RuntimeException("Unhandled statement row kind [{$row['kind']}]."),
            };

            $this->ledger->record(
                $portfolio,
                $account,
                $row['is_credit'] ? $row['amount'] : -$row['amount'],
                $type,
                $row['reference'] !== '' ? $row['reference'] : null,
                'Broker statement',
                Carbon::parse($row['occurred_on']),
                null,
                // The broker genuinely carried a debit balance on T+2 — see the
                // note on CashLedger::record().
                true,
            );

            $recorded++;
        }

        return $recorded;
    }

    /**
     * A scrip traded years ago may no longer be in the stock table; a stub
     * keeps the trade importable and is flagged unclassified rather than
     * guessed at.
     */
    private function stock(string $symbol): Stock
    {
        return Stock::firstOrCreate(
            ['symbol' => $symbol],
            ['name' => $symbol, 'instrument_type' => 'unknown'],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $trades
     * @return list<string>
     */
    private function unknownSymbols(array $trades): array
    {
        $symbols = array_unique(array_column($trades, 'symbol'));
        $known = Stock::whereIn('symbol', $symbols)->pluck('symbol')->all();

        return array_values(array_diff($symbols, $known));
    }
}
