<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Support\Numeric;
use RuntimeException;

/**
 * Parse a NAASA-style broker account statement into cash movements and trades.
 *
 * This is the statement that answers what the MeroShare imports cannot: the
 * MeroShare files describe a *position snapshot*, so the app's ledger held 24
 * synthetic buys and not one sell. The broker statement is the actual history —
 * every purchase, every sale, every rupee in and out.
 *
 * ## Why all four row kinds matter
 *
 * Importing only the deposits would put the whole contributed capital in the
 * cash column while the shares it bought are also counted, roughly doubling
 * net worth. The four kinds have to land together:
 *
 *     deposits          + money in from the client's bank
 *     buy settlements   - cash converted into shares
 *     sell settlements  + shares converted back into cash
 *     payments out      - money returned to the client's bank
 *
 * Their sum is the broker's own closing balance, which the statement prints.
 * That makes the import self-checking: {@see closingBalance()} is compared
 * against the replayed total rather than trusted.
 *
 * ## Telling the kinds apart
 *
 * The `Reference No.` column carries an unambiguous prefix — `B/` purchase,
 * `S/` sale, `R/` client receipt — so direction is read, never guessed. Only
 * the payment-out rows lack one, and they are identified by phrase.
 *
 * The trades themselves are embedded in the particulars, e.g.
 * `... bill no. B/0000001/057-058 (NABIL 20 kitta @ 300.50)`, and one bill can
 * settle several scrips. Bill references are stripped before scanning for the
 * trade tuples, because `B/0000002` would otherwise read as a quantity.
 */
final class BrokerStatementParser
{
    /** Row kinds, by reference prefix or phrase. */
    public const KIND_DEPOSIT = 'deposit';

    public const KIND_WITHDRAWAL = 'withdrawal';

    public const KIND_BUY = 'buy_settlement';

    public const KIND_SELL = 'sell_settlement';

    public const KIND_OPENING = 'opening_balance';

    /** `NABIL 20 kitta @ 300.50` — quantity is whole units, price may be fractional. */
    private const TRADE = '/\b([A-Z][A-Z0-9]{1,14})\s+(\d+(?:\.\d+)?)\s*kitta\s*@\s*([\d,]+(?:\.\d+)?)/i';

    /** `B/0000002/057-058`, `S/0000001/057-058`, `R/000001/057-058`. */
    private const BILL = '/\b[A-Z]{1,4}\/\d[\d\/\-]*/';

    private const US_DATE = '/\b(\d{1,2})\/(\d{1,2})\/(\d{4})\b/';

    public function __construct(
        private readonly XlsxReader $reader = new XlsxReader,
    ) {}

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     trades: list<array<string, mixed>>,
     *     closing_balance: float|null,
     *     opening_balance: float,
     *     warnings: list<string>,
     * }
     */
    public function parseFile(string $path): array
    {
        if (! $this->reader->looksLikeXlsx($path)) {
            throw new RuntimeException('That file is not an .xlsx broker statement.');
        }

        return $this->parseGrid($this->reader->read($path));
    }

    /**
     * @param  array<int, array<string, string>>  $grid  Row number => column letter => text.
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     trades: list<array<string, mixed>>,
     *     closing_balance: float|null,
     *     opening_balance: float,
     *     warnings: list<string>,
     * }
     */
    public function parseGrid(array $grid): array
    {
        $header = $this->headerRow($grid);
        $map = $this->columns($grid[$header]);

        $rows = [];
        $trades = [];
        $warnings = [];
        $opening = 0.0;
        $closing = null;

        ksort($grid);

        foreach ($grid as $number => $cells) {
            if ($number <= $header) {
                continue;
            }

            $particulars = trim($cells[$map['particulars']] ?? '');
            $reference = trim($cells[$map['reference']] ?? '');
            $rawDate = trim($cells[$map['date']] ?? '');
            $debit = Numeric::parse($cells[$map['debit']] ?? null) ?? 0.0;
            $credit = Numeric::parse($cells[$map['credit']] ?? null) ?? 0.0;
            $balance = $this->balance($cells[$map['balance']] ?? '');

            if ($particulars === '' && $reference === '') {
                continue;
            }

            if ($balance !== null) {
                $closing = $balance;
            }

            // The opening row carries a balance and nothing else.
            if ($rawDate === '' && stripos($particulars, 'opening balance') !== false) {
                $opening = $balance ?? 0.0;

                continue;
            }

            $date = $this->date($rawDate);

            if ($date === null) {
                $warnings[] = sprintf('Row %d has no readable date and was skipped: %s', $number, $rawDate);

                continue;
            }

            $kind = $this->kind($reference, $particulars);

            if ($kind === null) {
                $warnings[] = sprintf('Row %d could not be classified and was skipped: %s', $number, $particulars);

                continue;
            }

            $amount = $credit > 0.0 ? $credit : $debit;

            if ($amount <= 0.0) {
                $warnings[] = sprintf('Row %d has no amount and was skipped.', $number);

                continue;
            }

            $rows[] = [
                'line' => $number,
                'kind' => $kind,
                'occurred_on' => $date,
                'amount' => round($amount, 2),
                'is_credit' => $credit > 0.0,
                'reference' => $reference,
                'balance_after' => $balance,
            ];

            foreach ($this->trades($particulars, $kind, $date, $reference) as $trade) {
                $trades[] = $trade;
            }
        }

        return [
            'rows' => $rows,
            'trades' => $trades,
            'closing_balance' => $closing,
            'opening_balance' => $opening,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Replay the parsed rows and return the balance they imply.
     *
     * Compared against the statement's own printed closing balance, this is
     * what turns the import from "trust the parser" into a checkable claim.
     */
    public function replayBalance(array $rows, float $opening = 0.0): float
    {
        $balance = $opening;

        foreach ($rows as $row) {
            $balance += $row['is_credit'] ? $row['amount'] : -$row['amount'];
        }

        return round($balance, 2);
    }

    /**
     * @param  array<string, string>  $cells
     * @return array<string, string>
     */
    private function columns(array $cells): array
    {
        $wanted = [
            'date' => ['date ad', 'date'],
            'particulars' => ['particulars'],
            'reference' => ['reference no.', 'reference no', 'reference'],
            'debit' => ['debit'],
            'credit' => ['credit'],
            'balance' => ['balance'],
        ];

        $found = [];

        foreach ($cells as $column => $text) {
            $label = strtolower(trim($text));

            foreach ($wanted as $key => $labels) {
                if (! isset($found[$key]) && in_array($label, $labels, true)) {
                    $found[$key] = $column;
                }
            }
        }

        $missing = array_diff(array_keys($wanted), array_keys($found));

        if ($missing !== []) {
            throw new RuntimeException(
                'This does not look like a broker account statement — no '
                .implode(', ', $missing).' column was found.'
            );
        }

        return $found;
    }

    /**
     * @param  array<int, array<string, string>>  $grid
     */
    private function headerRow(array $grid): int
    {
        ksort($grid);

        foreach ($grid as $number => $cells) {
            $joined = strtolower(implode('|', $cells));

            if (str_contains($joined, 'particulars') && str_contains($joined, 'debit')) {
                return $number;
            }
        }

        throw new RuntimeException('No header row containing Particulars and Debit was found.');
    }

    private function kind(string $reference, string $particulars): ?string
    {
        $prefix = strtoupper(substr($reference, 0, 2));

        if ($prefix === 'B/') {
            return self::KIND_BUY;
        }

        if ($prefix === 'S/') {
            return self::KIND_SELL;
        }

        if ($prefix === 'R/') {
            return self::KIND_DEPOSIT;
        }

        // Payments back to the client carry a gateway reference, not a bill.
        if (stripos($particulars, 'payment made') !== false || stripos($particulars, 'paid to') !== false) {
            return self::KIND_WITHDRAWAL;
        }

        if (stripos($particulars, 'received from') !== false) {
            return self::KIND_DEPOSIT;
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function trades(string $particulars, string $kind, string $date, string $reference): array
    {
        if ($kind !== self::KIND_BUY && $kind !== self::KIND_SELL) {
            return [];
        }

        // Strip bill references first: `B/0000002/057-058` contains digits that
        // would otherwise be read as a quantity.
        $text = (string) preg_replace(self::BILL, ' ', $particulars);

        if (preg_match_all(self::TRADE, $text, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $trades = [];

        foreach ($matches as $match) {
            $quantity = (float) $match[2];
            $price = Numeric::parse($match[3]);

            if ($quantity <= 0.0 || $price === null || $price <= 0.0) {
                continue;
            }

            $trades[] = [
                'symbol' => strtoupper($match[1]),
                'side' => $kind === self::KIND_BUY ? 'buy' : 'sell',
                'quantity' => $quantity,
                'price' => $price,
                'transacted_on' => $date,
                'reference' => $reference,
            ];
        }

        return $trades;
    }

    /**
     * Fictional balances: `5,000.00 CR` / `250.00 DR`; debit is negative.
     */
    private function balance(string $text): ?float
    {
        $value = Numeric::parse($text);

        if ($value === null) {
            return null;
        }

        return stripos($text, 'DR') !== false ? -$value : $value;
    }

    /**
     * Month-first, explicitly. `6/9/2026` is 9 June — reading it as
     * 6 September would move a trade by three months.
     */
    private function date(string $text): ?string
    {
        if (preg_match(self::US_DATE, $text, $m) !== 1) {
            return null;
        }

        [$month, $day, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
