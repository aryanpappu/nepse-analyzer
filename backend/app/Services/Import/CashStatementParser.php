<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Support\Numeric;
use RuntimeException;

/**
 * Parses a broker cash statement into normalised money movements.
 *
 * This class is deliberately **pure**: string in, array out, no database, no
 * container, no clock. Every unit test drives it directly.
 *
 * Two real formats are handled.
 *
 * ## Format A — TMS deposit/withdraw log (`FORMAT_TMS`)
 *
 * Machine-exported, one row per attempted movement:
 *
 *     8bd7036e-d939-41af-a801-50b2ced3eed0  Deposit  100,000.00  ConnectIps  9/7/2026, 11:06:02 AM  SUCCESS
 *
 * Fields are located **by pattern, not by column position**. The same log
 * reaches us as a quoted CSV, as a tab-separated export and as a
 * whitespace-aligned copy/paste out of the browser, and the column order has
 * differed between those. A positional parser would silently read the channel
 * as the amount rather than fail, which is exactly the class of bug that
 * corrupts every money-weighted figure downstream.
 *
 * ## Format B — broker account statement (`FORMAT_LEDGER`)
 *
 * Narrative rather than tabular. Deposits appear as "Received from …" or
 * "Against bill(s) …" against purchase bills; withdrawals as "Payment made
 * for …" against sell bills, and in the sample we have the withdrawal rows
 * carry **only** a settlement reference with no direction word at all:
 *
 *     07/12/2024   11,407.17   S/0642440/080-081
 *
 * So direction cannot be inferred from the line alone. It is taken, in strict
 * precedence, from (1) a direction phrase in the line, (2) the section the
 * line sits under ("--- withdrawals ---", or any dateless heading naming a
 * direction), and failing both (3) nothing — the row is returned as
 * `ROW_NEEDS_REVIEW` for the user to set in the review step. Guessing would
 * flip the sign on a real cash flow, and a sign error on 11,407 is worse than
 * one extra click.
 *
 * ## Privacy
 *
 * A statement carries more identity than accounting needs. On ingest:
 * institution names collapse to a payment-rail token from a fixed allow-list
 * (`Global IME Bank Corporate` → `bank`), personal names following a
 * "received from" / "payment made for" phrase become `[name withheld]`, every
 * parenthetical memo is dropped (they are the user's own notes and routinely
 * contain names), and any digit run of eight or more is masked to its last
 * four — the same restraint {@see MeroshareCsvParser::maskDemat()} applies to
 * a demat number. Bill and settlement references are kept verbatim: they are
 * not personal, and they are the only way to reconcile a movement against the
 * trade that caused it.
 */
final class CashStatementParser
{
    public const FORMAT_TMS = 'tms_deposit_log';

    public const FORMAT_LEDGER = 'broker_ledger';

    /**
     * The broker's own .xlsx account statement.
     *
     * Read by {@see BrokerStatementParser}, which understands its column grid
     * (serial, BS date, AD date, particulars, DR, CR, balance). The two text
     * formats above cannot: they scan free text for a US-style date and an
     * inline amount, and on this file every row came back `needs_review` with
     * no amount at all.
     */
    public const FORMAT_BROKER_XLSX = 'broker_statement_xlsx';

    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    /** Settlement status of the movement itself, as the broker reported it. */
    public const SETTLED_SUCCESS = 'success';

    public const SETTLED_FAILED = 'failed';

    public const SETTLED_PENDING = 'pending';

    /** Row status the parser can assign. The service adds duplicate/overlap. */
    public const ROW_READY = 'ready';

    public const ROW_EXCLUDED_FAILED = 'excluded_failed';

    public const ROW_NEEDS_REVIEW = 'needs_review';

    /**
     * A UUID transaction id. Format A's natural idempotency key.
     */
    private const UUID = '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i';

    /**
     * US `M/D/YYYY` with an optional `, h:mm:ss AM/PM` clock.
     *
     * Month first, explicitly. `6/9/2026` is 9 June, and no locale default is
     * consulted to decide that — on a host set to en-GB a permissive parser
     * would read it as 6 September and quietly move a deposit three months.
     */
    private const US_DATETIME = '/\b(\d{1,2})\/(\d{1,2})\/(\d{4})\b(?:\s*,?\s*(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([AaPp])\.?[Mm]\.?)?/';

    /**
     * A money token: thousands separators optional, decimals optional.
     * Bounded on both sides so it cannot bite into a longer number.
     */
    private const MONEY = '/(?<![\d.])\d[\d,]*(?:\.\d+)?(?![\d.])/';

    /** Bill / settlement references, e.g. `B/0205643`, `S/0642440/080-081`. */
    private const REFERENCE = '/\b[A-Za-z]{1,4}\/\d[\d\/\-]*/';

    /**
     * Payment rails we are willing to record, longest-specific first.
     *
     * Anything unmatched becomes `other`. This is the privacy filter: a rail
     * ("esewa") describes how money moved, an institution name identifies
     * where the user banks, and only the former is needed to reconcile.
     *
     * @var array<string, string>
     */
    /**
     * Which kinds are money entering or leaving the account, as opposed to
     * cash moving against shares already owned.
     *
     * @var list<string>
     */
    public const CAPITAL_KINDS = ['deposit', 'withdrawal'];

    /** Human wording for the statement's row kinds. */
    private const KIND_LABELS = [
        'deposit' => 'Deposit',
        'withdrawal' => 'Withdrawal',
        'buy_settlement' => 'Paid for a purchase',
        'sell_settlement' => 'Proceeds from a sale',
    ];

    private const CHANNELS = [
        'connectips' => 'connect_ips',
        'connect ips' => 'connect_ips',
        'esewa' => 'esewa',
        'khalti' => 'khalti',
        'fonepay' => 'fonepay',
        'imepay' => 'ime_pay',
        'ime pay' => 'ime_pay',
        'cheque' => 'cheque',
        'check' => 'cheque',
        'bank' => 'bank',
        'cash' => 'cash',
    ];

    /** Phrases that mean money left the account. */
    private const OUT_PHRASES = ['payment made', 'withdraw', 'paid to', 'debit', 'transfer to'];

    /** Phrases that mean money came in. */
    private const IN_PHRASES = ['received from', 'on account', 'against bill', 'deposit', 'credit'];

    /** Settlement vocabulary, case-insensitively matched. */
    private const SETTLEMENT_WORDS = [
        'success' => self::SETTLED_SUCCESS,
        'successful' => self::SETTLED_SUCCESS,
        'completed' => self::SETTLED_SUCCESS,
        'complete' => self::SETTLED_SUCCESS,
        'settled' => self::SETTLED_SUCCESS,
        'approved' => self::SETTLED_SUCCESS,
        'failed' => self::SETTLED_FAILED,
        'fail' => self::SETTLED_FAILED,
        'failure' => self::SETTLED_FAILED,
        'rejected' => self::SETTLED_FAILED,
        'reversed' => self::SETTLED_FAILED,
        'cancelled' => self::SETTLED_FAILED,
        'canceled' => self::SETTLED_FAILED,
        'declined' => self::SETTLED_FAILED,
        'expired' => self::SETTLED_FAILED,
        'pending' => self::SETTLED_PENDING,
        'processing' => self::SETTLED_PENDING,
        'initiated' => self::SETTLED_PENDING,
    ];

    /** Words that mark a line as a column header rather than a movement. */
    private const HEADER_WORDS = [
        'transaction', 'amount', 'status', 'channel', 'type', 'date', 'remarks',
        'particulars', 'narration', 'balance', 'debit', 'credit', 'voucher',
    ];

    /**
     * Which format a statement is, from its content.
     *
     * @return self::FORMAT_TMS|self::FORMAT_LEDGER
     */
    public function detectFormat(string $raw): string
    {
        if (preg_match(self::UUID, $raw) === 1) {
            return self::FORMAT_TMS;
        }

        if (preg_match(self::US_DATETIME, $raw) === 1) {
            return self::FORMAT_LEDGER;
        }

        throw new RuntimeException(
            'This does not look like a cash statement. Expected either a TMS deposit/withdraw '
            .'log (rows carrying a UUID transaction id) or a broker account statement '
            .'(rows starting with an M/D/YYYY date).'
        );
    }

    /**
     * Parse a whole statement.
     *
     * @param  string|null  $format  Force a format; null auto-detects.
     * @return array{format: string, rows: list<array<string, mixed>>, warnings: list<string>}
     */
    public function parse(string $raw, ?string $format = null): array
    {
        $format ??= $this->detectFormat($raw);

        return match ($format) {
            self::FORMAT_TMS => $this->parseTmsLog($raw),
            self::FORMAT_LEDGER => $this->parseLedger($raw),
            default => throw new RuntimeException("Unknown cash statement format [{$format}]."),
        };
    }

    /**
     * Format A — the TMS deposit/withdraw log.
     *
     * @return array{format: string, rows: list<array<string, mixed>>, warnings: list<string>}
     */
    /**
     * Map a parsed .xlsx broker statement's cash side into staged rows.
     *
     * The statement's cash rows are four kinds, and only two of them are money
     * crossing the boundary of the account:
     *
     *   deposit, withdrawal        money you put in or took out
     *   buy/sell settlement        cash moving against shares you already own
     *
     * All four are staged, because the running balance only reconciles to the
     * statement's printed closing figure if every row is present. But the
     * `kind` rides along so the summary can report contributed capital without
     * counting a sell settlement as new money — a fictional net capital of
     * 70,000.00 is distinct from gross settlement movements.
     *
     * @param  list<array<string, mixed>>  $cashRows  BrokerStatementParser `rows`.
     * @param  list<string>  $warnings
     * @return array{format: string, rows: list<array<string, mixed>>, warnings: list<string>}
     */
    public function fromBrokerStatement(array $cashRows, array $warnings = []): array
    {
        $rows = [];

        foreach ($cashRows as $row) {
            $kind = (string) ($row['kind'] ?? '');
            $amount = $row['amount'] === null ? null : round((float) $row['amount'], 2);
            $isCredit = (bool) ($row['is_credit'] ?? false);

            $rows[] = $this->row([
                'line' => (int) ($row['line'] ?? 0),
                'external_id' => null,
                'direction' => $isCredit ? self::DIRECTION_IN : self::DIRECTION_OUT,
                'amount' => $amount,
                'occurred_on' => $row['occurred_on'] ?? null,
                'occurred_at' => null,
                'kind' => $kind === '' ? null : $kind,
                'channel' => null,
                // A posted statement line has already settled.
                'settlement' => self::SETTLED_SUCCESS,
                'reference' => $row['reference'] ?? null,
                'narration' => self::KIND_LABELS[$kind] ?? $kind,
                'balance_after' => $row['balance_after'] ?? null,
                'status' => $amount === null || $amount <= 0.0 ? self::ROW_NEEDS_REVIEW : self::ROW_READY,
                'include' => $amount !== null && $amount > 0.0,
                'reason' => $amount === null || $amount <= 0.0
                    ? 'No amount on this line — enter it in review.'
                    : null,
            ]);
        }

        if ($rows === []) {
            throw new RuntimeException('No cash movements were found in that statement.');
        }

        return [
            'format' => self::FORMAT_BROKER_XLSX,
            'rows' => $rows,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    public function parseTmsLog(string $raw): array
    {
        $rows = [];
        $warnings = [];
        $seen = [];

        foreach ($this->lines($raw) as $number => $line) {
            if ($this->isHeader($line)) {
                continue;
            }

            $row = $this->normalizeTmsRow($line, $number);

            if ($row === null) {
                continue;
            }

            // A UUID repeated inside one file is the same movement listed
            // twice, not two movements. Flagged here so the count the user
            // sees in review already excludes it.
            if ($row['fingerprint'] !== null && isset($seen[$row['fingerprint']])) {
                $row['status'] = self::ROW_NEEDS_REVIEW;
                $row['include'] = false;
                $row['reason'] = sprintf(
                    'Transaction id repeats line %d of this file — listed twice, counted once.',
                    $seen[$row['fingerprint']],
                );
                $warnings[] = $row['reason'];
            } elseif ($row['fingerprint'] !== null) {
                $seen[$row['fingerprint']] = $number;
            }

            if ($row['status'] === self::ROW_NEEDS_REVIEW && ! isset($row['reason'])) {
                $warnings[] = "Line {$number}: could not be read confidently — check it in review.";
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            throw new RuntimeException('No cash movements were found in that statement.');
        }

        return ['format' => self::FORMAT_TMS, 'rows' => $rows, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * One Format A line → one normalised movement, or null if the line holds
     * no movement at all. Pure; exposed so a caller can normalise rows it
     * already has without re-splitting a file.
     *
     * @return array<string, mixed>|null
     */
    public function normalizeTmsRow(string $line, int $lineNumber = 0): ?array
    {
        $line = Numeric::text($line);

        if ($line === '') {
            return null;
        }

        $remainder = $line;

        // 1. Transaction id — the idempotency key.
        $externalId = null;

        if (preg_match(self::UUID, $remainder, $m) === 1) {
            $externalId = strtolower($m[0]);
            $remainder = $this->removeFirst($remainder, $m[0]);
        }

        // 2. Timestamp. Removed before amount detection so the day, month and
        //    clock digits cannot be mistaken for money.
        $when = $this->extractUsDateTime($remainder);

        if ($when === null) {
            // No date at all: not a movement row (a footer, a page number).
            return null;
        }

        $remainder = $when['remainder'];

        // 3. Settlement status, anywhere in the line, any casing. The log
        //    writes both `SUCCESS` and `Success`, so this compares
        //    case-insensitively rather than against a literal.
        [$settlement, $settlementWord, $remainder] = $this->extractSettlement($remainder);

        // 4. Direction. `Withdraw (Quick Withdraw)`, `Withdraw`,
        //    `Quick Withdrawal` are all withdrawals: matched on a normalised
        //    prefix, never on the whole string.
        $direction = $this->direction($remainder);

        // 5. Amount, from whatever is left.
        $amount = $this->extractAmount($remainder);

        $status = self::ROW_READY;
        $include = true;
        $reason = null;

        if ($settlement === self::SETTLED_FAILED) {
            // A failed top-up never moved money. Counting it would overstate
            // the capital put in and understate the return on it.
            $status = self::ROW_EXCLUDED_FAILED;
            $include = false;
            $reason = sprintf('Broker reported this movement as %s — no money moved.', $settlementWord ?? 'failed');
        } elseif ($amount === null || $amount <= 0.0) {
            $status = self::ROW_NEEDS_REVIEW;
            $include = false;
            $reason = 'No single unambiguous amount on this line — enter it in review.';
        } elseif ($direction === null) {
            $status = self::ROW_NEEDS_REVIEW;
            $include = false;
            $reason = 'Neither a deposit nor a withdrawal could be read from this line.';
        } elseif ($settlement === self::SETTLED_PENDING) {
            $status = self::ROW_NEEDS_REVIEW;
            $include = false;
            $reason = 'Still pending at the broker — include it only once it settles.';
        }

        return $this->row([
            'line' => $lineNumber,
            'external_id' => $externalId,
            'direction' => $direction,
            'amount' => $amount,
            'occurred_on' => $when['date'],
            'occurred_at' => $when['datetime'],
            'channel' => $this->channel($line),
            'settlement' => $settlement,
            'reference' => null,
            'narration' => $this->redact($remainder),
            'status' => $status,
            'include' => $include,
            'reason' => $reason,
        ]);
    }

    /**
     * Format B — the narrative broker account statement.
     *
     * @return array{format: string, rows: list<array<string, mixed>>, warnings: list<string>}
     */
    public function parseLedger(string $raw): array
    {
        $rows = [];
        $warnings = [];
        $section = null;

        foreach ($this->lines($raw) as $number => $line) {
            $when = $this->extractUsDateTime($line);

            if ($when === null) {
                // A dateless line is either a heading or noise. A heading that
                // names a direction switches the section every following row
                // inherits — which is the only thing that tells us which way
                // a bare `07/12/2024  11,407.17  S/0642440/080-081` went.
                $named = $this->direction($line);

                if ($named !== null && ! $this->isHeader($line)) {
                    $section = $named;
                }

                continue;
            }

            if ($this->isHeader($line)) {
                continue;
            }

            $remainder = $when['remainder'];

            // References out of the way before amount detection: a bill number
            // is digits, and `B/0205643` would otherwise read as 205,643.
            $references = [];

            if (preg_match_all(self::REFERENCE, $remainder, $m) > 0) {
                $references = array_map(static fn (string $ref): string => strtoupper($ref), $m[0]);
                foreach ($m[0] as $ref) {
                    $remainder = $this->removeFirst($remainder, $ref);
                }
            }

            // Parenthetical memos go before amount detection as well as
            // before storage: "(collateral 3)" contributes a bare 3 that
            // would make the line's amount ambiguous and push a perfectly
            // readable row into needs_review.
            $remainder = preg_replace('/\([^)]*\)/', ' ', $remainder) ?? $remainder;

            // `Collateral2`, `DemoMemo1` — a memo word with digits glued on.
            $remainder = preg_replace('/\b[A-Za-z]+\d+[A-Za-z0-9]*\b/', ' ', $remainder) ?? $remainder;

            $amount = $this->extractAmount($remainder);
            $direction = $this->direction($remainder) ?? $section;

            $status = self::ROW_READY;
            $include = true;
            $reason = null;

            if ($amount === null || $amount <= 0.0) {
                $status = self::ROW_NEEDS_REVIEW;
                $include = false;
                $reason = 'No single unambiguous amount on this line — enter it in review.';
            } elseif ($direction === null) {
                $status = self::ROW_NEEDS_REVIEW;
                $include = false;
                $reason = 'This line does not say whether money came in or went out — set it in review.';
                $warnings[] = "Line {$number}: direction unknown, set it before committing.";
            }

            $rows[] = $this->row([
                'line' => $number,
                'external_id' => null,
                'direction' => $direction,
                'amount' => $amount,
                'occurred_on' => $when['date'],
                'occurred_at' => $when['datetime'],
                'channel' => $this->channel($line),
                // A posted ledger line has already settled; the statement has
                // no status column to say otherwise.
                'settlement' => self::SETTLED_SUCCESS,
                'reference' => $references === [] ? null : implode(', ', $references),
                'narration' => $this->redact($remainder),
                'status' => $status,
                'include' => $include,
                'reason' => $reason,
            ]);
        }

        if ($rows === []) {
            throw new RuntimeException('No cash movements were found in that statement.');
        }

        return ['format' => self::FORMAT_LEDGER, 'rows' => $rows, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * Stable identity for a movement, used as the idempotency key.
     *
     * A UUID is the movement's own id and is used alone, so the same statement
     * re-uploaded — or a later export whose rows were re-ordered or
     * re-formatted — collides exactly. Without one (Format B) the key is the
     * tuple that the statement does guarantee: date, signed amount and
     * reference.
     */
    public function fingerprint(?string $externalId, ?string $date, ?float $amount, ?string $direction, ?string $reference): ?string
    {
        if ($externalId !== null && $externalId !== '') {
            return hash('sha256', 'uuid:'.strtolower($externalId));
        }

        if ($date === null || $amount === null || $direction === null) {
            // Not enough to identify the row yet; the service assigns one once
            // the user has filled the gap in review.
            return null;
        }

        return hash('sha256', implode('|', [
            'ledger',
            $date,
            number_format($amount, 2, '.', ''),
            $direction,
            strtoupper((string) $reference),
        ]));
    }

    // ------------------------------------------------------------------
    // Field extraction
    // ------------------------------------------------------------------

    /**
     * A US `M/D/YYYY[, h:mm:ss AM/PM]` stamp, and the line with it removed.
     *
     * @return array{date: string, datetime: ?string, remainder: string}|null
     */
    public function extractUsDateTime(string $text): ?array
    {
        if (preg_match(self::US_DATETIME, $text, $m) !== 1) {
            return null;
        }

        $month = (int) $m[1];
        $day = (int) $m[2];
        $year = (int) $m[3];

        // Month-first, asserted. A 13+ in the first field is a D/M/YYYY file
        // and we refuse to guess rather than move the movement by months.
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $datetime = null;

        if (($m[4] ?? '') !== '') {
            $hour = (int) $m[4];
            $meridiem = strtolower($m[7] ?? '');

            // 12 AM is hour 0, 12 PM is hour 12 — the two cases a naive
            // `+12 if PM` gets wrong.
            if ($meridiem === 'a') {
                $hour = $hour === 12 ? 0 : $hour;
            } elseif ($meridiem === 'p') {
                $hour = $hour === 12 ? 12 : $hour + 12;
            }

            $datetime = sprintf('%s %02d:%02d:%02d', $date, $hour, (int) $m[5], (int) ($m[6] ?? 0));
        }

        return [
            'date' => $date,
            'datetime' => $datetime,
            'remainder' => $this->removeFirst($text, $m[0]),
        ];
    }

    /**
     * The one money token on a line, or null when there is not exactly one.
     *
     * Returning null rather than picking the largest candidate is deliberate:
     * an amount guessed out of two is a wrong number nobody will notice, while
     * a null becomes a `needs_review` row the user resolves in one click.
     */
    public function extractAmount(string $text): ?float
    {
        if (preg_match_all(self::MONEY, $text, $m) === 0) {
            return null;
        }

        $tokens = $m[0];

        if (count($tokens) > 1) {
            // Prefer the ones formatted as money — a thousands separator or a
            // decimal part — which discards stray bare integers.
            $formatted = array_values(array_filter(
                $tokens,
                static fn (string $token): bool => str_contains($token, ',') || str_contains($token, '.'),
            ));

            if (count($formatted) !== 1) {
                return null;
            }

            $tokens = $formatted;
        }

        // Thousands separators are stripped here rather than trusted to a
        // locale-aware cast: `(float) '100,000.00'` is 100.0.
        $parsed = Numeric::parse($tokens[0]);

        return $parsed === null ? null : round($parsed, 2);
    }

    /**
     * Which way the money went, from a direction phrase.
     *
     * Matched on a normalised substring so every variant the log produces —
     * `Withdraw`, `Withdraw (Quick Withdraw)`, `Quick Withdrawal`,
     * `Payment made for` — lands on the same answer. Withdrawal phrases are
     * tested first: "Withdraw (Quick Withdraw)" contains no deposit word, but
     * a future "Deposit reversal / withdraw" would, and money leaving is the
     * safer reading of an ambiguous line.
     */
    public function direction(string $text): ?string
    {
        $normalized = strtolower(Numeric::text($text));

        foreach (self::OUT_PHRASES as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return self::DIRECTION_OUT;
            }
        }

        foreach (self::IN_PHRASES as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return self::DIRECTION_IN;
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: ?string, 2: string} settlement, the word matched, remainder
     */
    private function extractSettlement(string $text): array
    {
        foreach (self::SETTLEMENT_WORDS as $word => $settlement) {
            // Matched case-insensitively against the original text — the log
            // writes both `SUCCESS` and `Success`, and the word is removed
            // from the line in whatever casing it actually appeared in.
            if (preg_match('/\b'.preg_quote($word, '/').'\b/i', $text, $m) === 1) {
                return [$settlement, $m[0], $this->removeFirst($text, $m[0])];
            }
        }

        // No status column at all (some exports drop it) — treat as settled,
        // which is what a row on a statement normally means.
        return [self::SETTLED_SUCCESS, null, $text];
    }

    /**
     * The payment rail, from the allow-list only.
     *
     * `Global IME Bank Corporate` becomes `bank`: the rail is what reconciles
     * a movement, the institution is identity we have no reason to keep.
     */
    private function channel(string $line): string
    {
        $normalized = strtolower(Numeric::text($line));

        foreach (self::CHANNELS as $needle => $token) {
            if (str_contains($normalized, $needle)) {
                return $token;
            }
        }

        return 'other';
    }

    /**
     * Strip identity out of a narration before it is stored.
     */
    public function redact(string $text): string
    {
        $clean = Numeric::text($text);

        // Parenthetical memos are the user's own notes and routinely carry a
        // name ("(DemoMemo1)"). Nothing downstream reads them.
        $clean = preg_replace('/\([^)]*\)/', ' ', $clean) ?? $clean;

        // A name following a counterparty phrase.
        $clean = preg_replace(
            '/\b(received from|payment made for|payment made to|paid to)\b[^,;]*/i',
            '$1 [name withheld]',
            $clean,
        ) ?? $clean;

        // The institution, and the words naming it. `channel` already records
        // which rail the money moved on, which is everything reconciliation
        // needs; *where the user banks* is identity we have no use for, so it
        // does not reach storage even as free text.
        $clean = preg_replace(
            '/(?:[A-Za-z][\w.&\'-]*\s+){0,3}\b(?:bank|finance|capital|securities|microfinance|bikas)\b'
            .'(?:\s+(?:ltd\.?|limited|corporate|pvt\.?|co\.?))?/i',
            '[institution withheld]',
            $clean,
        ) ?? $clean;

        // Account-number-shaped digit runs keep only their last four, exactly
        // as the MeroShare importer treats a demat number.
        $clean = preg_replace_callback(
            '/\b\d{8,}\b/',
            static fn (array $m): string => '****'.substr($m[0], -4),
            $clean,
        ) ?? $clean;

        return Numeric::text($clean);
    }

    // ------------------------------------------------------------------
    // Mechanics
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function row(array $row): array
    {
        $row['fingerprint'] = $this->fingerprint(
            $row['external_id'],
            $row['occurred_on'],
            $row['amount'],
            $row['direction'],
            $row['reference'],
        );

        // Signed once, here, so no consumer has to remember the convention.
        $row['signed_amount'] = $row['amount'] === null || $row['direction'] === null
            ? null
            : ($row['direction'] === self::DIRECTION_OUT ? -$row['amount'] : $row['amount']);

        $row['edited'] = false;

        return $row;
    }

    /**
     * @return array<int, string> Keyed by 1-based line number.
     */
    private function lines(string $raw): array
    {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        $lines = [];

        foreach (explode("\n", $raw) as $index => $line) {
            // CSV quoting and tabs carry no information once fields are found
            // by pattern, but a quote glued to a number would break the money
            // regex's boundary assertions.
            $clean = str_replace(['"', "\t", ';', '|'], ' ', $line);
            $clean = Numeric::text($clean);

            if ($clean === '' || trim($clean, ' -=_*') === '') {
                continue;
            }

            $lines[$index + 1] = $clean;
        }

        return $lines;
    }

    /**
     * Whether a line is a column header rather than a movement.
     */
    private function isHeader(string $line): bool
    {
        if (preg_match('/\d/', $line) === 1 && preg_match(self::US_DATETIME, $line) === 1) {
            return false;
        }

        $normalized = strtolower($line);
        $hits = 0;

        foreach (self::HEADER_WORDS as $word) {
            if (str_contains($normalized, $word)) {
                $hits++;
            }
        }

        return $hits >= 2;
    }

    private function removeFirst(string $haystack, string $needle): string
    {
        $position = strpos($haystack, $needle);

        if ($position === false) {
            return $haystack;
        }

        return Numeric::text(substr_replace($haystack, ' ', $position, strlen($needle)));
    }
}
