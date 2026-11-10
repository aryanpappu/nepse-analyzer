<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\CashStatementEntry;
use App\Models\CashStatementImport;
use App\Models\CashTransaction;
use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use App\Services\Portfolio\CashLedger;
use App\Services\Portfolio\IpoCapitalService;
use App\Services\Portfolio\PerformanceService;
use App\Services\Portfolio\PortfolioValuationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Staged, reviewable import of a broker cash statement.
 *
 * Same shape as the MeroShare wizard: parse into rows the user can edit,
 * classify each row, let them fix what the file could not say, then commit.
 * The differences are all in the commit.
 *
 * ## Everything goes through CashLedger
 *
 * Not one write here touches `cash_transactions` or the two balance columns
 * directly. Balances must stay reconstructable by replaying the ledger, and an
 * importer that wrote rows itself would be the one path that could produce a
 * balance no sequence of deposits and withdrawals explains.
 *
 * ## Chronological ordering, and the back-dated overdraw
 *
 * `CashLedger::record()` validates against the account's *current* balance, not
 * the balance as it stood on the movement's date — so a back-dated statement
 * can legitimately trip it. Two things resolve that, and neither bypasses the
 * rule:
 *
 * 1. Rows are committed **oldest first**. Within one statement the deposits
 *    that funded a withdrawal precede it, so the running balance never dips
 *    below zero for a coherent statement. Committing in file order — which for
 *    the TMS log is newest first — would replay a withdrawal before its
 *    deposit and fail on a perfectly valid statement.
 * 2. The whole sequence is **pre-flighted** in {@see plan()} before anything is
 *    written. If some prefix would overdraw, the commit is refused up front,
 *    naming the row and the shortfall, rather than half-importing and throwing
 *    partway. A statement that genuinely starts mid-history — the TMS log
 *    opens by withdrawing a balance carried in from an earlier statement —
 *    is handled by passing `$openingBalance`, which records a dated opening
 *    deposit through the same `CashLedger` before the statement's own rows.
 *    That is a real movement (money was carried in), not a patch.
 */
final class CashStatementImportService
{
    /** Will be imported. */
    public const ROW_READY = 'ready';

    /** The broker reported the movement as failed — no money moved. */
    public const ROW_EXCLUDED_FAILED = 'excluded_failed';

    /** Already in the ledger from an earlier import of the same movement. */
    public const ROW_DUPLICATE = 'duplicate';

    /** The file could not say something we need; the user supplies it. */
    public const ROW_NEEDS_REVIEW = 'needs_review';

    /** Looks like a movement another import already covered. */
    public const ROW_OVERLAP_WARNING = 'overlap_warning';

    public function __construct(
        private readonly CashStatementParser $parser,
        private readonly CashLedger $ledger,
        private readonly PortfolioValuationService $valuation = new PortfolioValuationService,
    ) {}

    /**
     * Parse a statement and stage it for review.
     */
    /**
     * Stage an .xlsx broker statement.
     *
     * Routed through {@see BrokerStatementParser} because that is the parser
     * which understands the file's column grid; the text parsers read it as 87
     * unamendable `needs_review` rows with no amounts.
     *
     * **Only the cash side is taken.** The same parse also yields trade rows,
     * and they are deliberately dropped here: a broker statement records where
     * money went in and came out, and has no authority over what is held or
     * what it is worth. Holdings come from the MeroShare exports, and a scrip
     * the statement trades but MeroShare no longer lists keeps the history it
     * already has rather than being re-derived from this file.
     */
    public function startFromXlsx(
        Portfolio $portfolio,
        string $path,
        string $filename,
        ?string $account = null,
    ): CashStatementImport {
        $account = $this->resolveAccount($account);

        $parsed = (new BrokerStatementParser)->parseFile($path);
        $staged = $this->parser->fromBrokerStatement($parsed['rows'], $parsed['warnings']);

        $import = CashStatementImport::create([
            'portfolio_id' => $portfolio->id,
            'user_id' => $portfolio->user_id,
            'format' => $staged['format'],
            'status' => CashStatementImport::STATUS_READY,
            'account' => $account,
            'filename' => $filename,
            'parsed_rows' => $staged['rows'],
            'parsed_count' => count($staged['rows']),
            // The statement prints its own closing balance; carrying it lets
            // the review screen say whether the staged rows reproduce it.
            'reconciliation' => [
                'statement_closing_balance' => $parsed['closing_balance'],
                'replayed_closing_balance' => (new BrokerStatementParser)->replayBalance(
                    $parsed['rows'],
                    $parsed['opening_balance'],
                ),
            ],
        ]);

        return $this->rebuildPreview($import, $staged['warnings']);
    }

    public function startImport(
        Portfolio $portfolio,
        string $contents,
        string $filename,
        ?string $format = null,
        ?string $account = null,
    ): CashStatementImport {
        $account = $this->resolveAccount($account);

        $parsed = $this->parser->parse($contents, $format);

        $import = CashStatementImport::create([
            'portfolio_id' => $portfolio->id,
            'user_id' => $portfolio->user_id,
            'format' => $parsed['format'],
            'status' => CashStatementImport::STATUS_READY,
            'account' => $account,
            'filename' => $filename,
            'parsed_rows' => $parsed['rows'],
            'parsed_count' => count($parsed['rows']),
        ]);

        return $this->rebuildPreview($import, $parsed['warnings']);
    }

    /**
     * Re-classify every staged row against the ledger as it stands now, and
     * re-apply the user's edits on top.
     *
     * @param  list<string>  $parseWarnings
     */
    public function rebuildPreview(CashStatementImport $import, array $parseWarnings = []): CashStatementImport
    {
        $rows = $import->parsed_rows ?? [];

        // Edits survive a re-merge, exactly as they do in the MeroShare
        // wizard — a user who typed a direction into three rows should not
        // lose it because a later row was re-classified.
        $edits = collect($import->merged_rows ?? [])
            ->filter(static fn (array $row): bool => (bool) ($row['edited'] ?? false))
            ->keyBy(static fn (array $row, int $index): string => (string) ($row['fingerprint'] ?? 'line:'.($row['line'] ?? $index)));

        $rows = array_map(function (array $row) use ($edits): array {
            $key = (string) ($row['fingerprint'] ?? 'line:'.($row['line'] ?? 0));

            if (! $edits->has($key)) {
                return $row;
            }

            return $this->applyEdit($row, $edits->get($key));
        }, $rows);

        $existing = $this->committedEntries($import);
        $overlap = CashStatementOverlapDetector::detect($rows, $existing);

        $warnings = $parseWarnings === [] ? ($import->warnings ?? []) : $parseWarnings;
        $warnings = array_values(array_unique([...$warnings, ...$overlap['warnings']]));

        $rows = array_map(
            fn (array $row): array => $this->classify($row, $overlap),
            $rows,
        );

        // Oldest first, so the review table reads in the order the money
        // actually moved and in the order it will be committed.
        usort($rows, static fn (array $a, array $b): int => [$a['occurred_on'] ?? '', $a['line'] ?? 0]
            <=> [$b['occurred_on'] ?? '', $b['line'] ?? 0]);

        $totals = CashStatementReconciler::totals($rows);

        $import->fill([
            'merged_rows' => $rows,
            'warnings' => $warnings,
            'overlap' => $overlap,
            'ready_count' => count(array_filter($rows, static fn (array $r): bool => (bool) $r['include'])),
            'excluded_count' => count(array_filter($rows, static fn (array $r): bool => ! (bool) $r['include'])),
            'duplicate_count' => count(array_filter(
                $rows,
                static fn (array $r): bool => $r['status'] === self::ROW_DUPLICATE,
            )),
            'money_in' => $totals['money_in'],
            'money_out' => $totals['money_out'],
            'net_injected' => $totals['net_injected'],
            'first_movement_on' => $totals['first_movement_on'],
            'last_movement_on' => $totals['last_movement_on'],
        ])->save();

        return $import->refresh();
    }

    /**
     * Edit one staged row before committing.
     *
     * @param  string  $key  The row's fingerprint, or `line:<n>` for a row too
     *                       incomplete to have one yet.
     * @param  array<string, mixed>  $changes  Any of: direction, amount, occurred_on, include, reference.
     */
    public function updateRow(CashStatementImport $import, string $key, array $changes): CashStatementImport
    {
        $this->assertEditable($import);

        $rows = $import->merged_rows ?? [];
        $found = false;

        foreach ($rows as $index => $row) {
            if ((string) ($row['fingerprint'] ?? 'line:'.($row['line'] ?? $index)) !== $key) {
                continue;
            }

            $rows[$index] = $this->applyEdit($row, $changes);
            $found = true;
            break;
        }

        if (! $found) {
            throw new RuntimeException('That row is not part of this import.');
        }

        // Written back to the parsed set as well, so the re-merge in
        // rebuildPreview() classifies the corrected row rather than the
        // original one and then re-applies the edit on top of itself.
        $import->merged_rows = $rows;
        $import->save();

        return $this->rebuildPreview($import);
    }

    /**
     * Order the rows the way they will be committed and check that no prefix
     * overdraws the account.
     *
     * Pure — no database, no clock — so the overdraw rule is unit-testable
     * without a portfolio.
     *
     * @param  list<array<string, mixed>>  $rows  Rows to commit, any order.
     * @param  float  $openingBalance  The account balance the sequence starts from.
     * @return array{ordered: list<array<string, mixed>>, shortfall: ?array{line: int, occurred_on: ?string, amount: float, balance: float, short_by: float}, ending: float}
     */
    public static function plan(array $rows, float $openingBalance): array
    {
        usort($rows, static fn (array $a, array $b): int => [$a['occurred_on'] ?? '', $a['line'] ?? 0]
            <=> [$b['occurred_on'] ?? '', $b['line'] ?? 0]);

        $balance = round($openingBalance, 2);
        $shortfall = null;

        foreach ($rows as $row) {
            $signed = (float) ($row['signed_amount'] ?? 0.0);
            $next = round($balance + $signed, 2);

            if ($next < -0.005 && $shortfall === null) {
                // Reported, not thrown, so the caller can name every detail
                // of the first failing row in one message.
                $shortfall = [
                    'line' => (int) ($row['line'] ?? 0),
                    'occurred_on' => $row['occurred_on'] ?? null,
                    'amount' => abs($signed),
                    'balance' => $balance,
                    'short_by' => round(abs($next), 2),
                ];
            }

            $balance = $next;
        }

        return ['ordered' => $rows, 'shortfall' => $shortfall, 'ending' => $balance];
    }

    /**
     * Write the included rows into the cash ledger.
     *
     * @param  float|null  $openingBalance  Record a dated opening deposit of this much
     *                                      before the statement's own rows — for a statement that starts
     *                                      mid-history with a balance carried in from an earlier one.
     */
    public function commit(CashStatementImport $import, ?float $openingBalance = null): CashStatementImport
    {
        $this->assertEditable($import);

        $portfolio = $import->portfolio;
        $account = (string) $import->account;

        $rows = array_values(array_filter(
            $import->merged_rows ?? [],
            static fn (array $row): bool => (bool) ($row['include'] ?? false)
                && ($row['amount'] ?? null) !== null
                && ($row['direction'] ?? null) !== null
                && ($row['occurred_on'] ?? null) !== null
                && ($row['fingerprint'] ?? null) !== null,
        ));

        if ($rows === []) {
            throw new RuntimeException(
                'Nothing is ready to import. Every row is either excluded, already in the ledger, '
                .'or still missing a date, amount or direction.'
            );
        }

        $column = CashTransaction::balanceColumn($account);
        $startingBalance = round((float) $portfolio->{$column}, 2)
            + ($openingBalance === null ? 0.0 : round(abs($openingBalance), 2));

        $plan = self::plan($rows, $startingBalance);

        if ($plan['shortfall'] !== null) {
            $shortfall = $plan['shortfall'];

            throw new RuntimeException(sprintf(
                'This statement cannot be imported as it stands: the withdrawal of NPR %s on %s '
                .'would overdraw the %s account by NPR %s — only NPR %s has been paid in by that '
                .'point. That normally means the statement starts part-way through the account\'s '
                .'history. Import the earlier statement first, or supply the balance carried in '
                .'as an opening deposit.',
                number_format($shortfall['amount'], 2),
                (string) ($shortfall['occurred_on'] ?? 'an unknown date'),
                $account === CashTransaction::ACCOUNT_BANK ? 'bank' : 'cash',
                number_format($shortfall['short_by'], 2),
                number_format($shortfall['balance'], 2),
            ));
        }

        $committed = 0;

        DB::transaction(function () use ($import, $portfolio, $account, $plan, $openingBalance, &$committed): void {
            if ($openingBalance !== null && abs($openingBalance) >= 0.01) {
                // Through the ledger like everything else — the carried-in
                // balance is a real deposit that happened before the window
                // this statement covers.
                $this->ledger->deposit(
                    $portfolio,
                    $account,
                    abs($openingBalance),
                    null,
                    'Balance carried into '.($import->filename ?? 'the imported statement').'.',
                    $this->openingDate($plan['ordered']),
                );
            }

            foreach ($plan['ordered'] as $row) {
                $direction = (string) $row['direction'];
                $amount = round((float) $row['amount'], 2);

                $transaction = $this->ledger->record(
                    $portfolio,
                    $account,
                    $direction === CashStatementParser::DIRECTION_OUT ? -$amount : $amount,
                    $direction === CashStatementParser::DIRECTION_OUT ? 'withdrawal' : 'deposit',
                    // The broker's own id is the reference, so a ledger row
                    // can always be traced back to the statement line.
                    $row['external_id'] ?? $row['reference'] ?? null,
                    $this->ledgerNote($import, $row),
                    Carbon::parse((string) $row['occurred_on']),
                );

                CashStatementEntry::create([
                    'portfolio_id' => $portfolio->id,
                    'cash_statement_import_id' => $import->id,
                    'cash_transaction_id' => $transaction->id,
                    'fingerprint' => (string) $row['fingerprint'],
                    'external_id' => $row['external_id'] ?? null,
                    'direction' => $direction,
                    'amount' => $amount,
                    'occurred_on' => (string) $row['occurred_on'],
                    'reference' => $row['reference'] ?? null,
                    'channel' => $row['channel'] ?? null,
                ]);

                $committed++;
            }
        });

        $import->fill([
            'status' => CashStatementImport::STATUS_COMMITTED,
            'committed_count' => $committed,
            'committed_at' => Carbon::now(),
            'reconciliation' => $this->reconcile($portfolio->refresh(), $import->merged_rows ?? []),
        ])->save();

        Log::info('Cash statement import committed', [
            'import_id' => $import->id,
            'portfolio_id' => $portfolio->id,
            'format' => $import->format,
            'movements' => $committed,
            'net_injected' => $import->net_injected,
        ]);

        return $import->refresh();
    }

    public function cancel(CashStatementImport $import): CashStatementImport
    {
        $this->assertEditable($import);

        $import->update(['status' => CashStatementImport::STATUS_CANCELLED]);

        return $import;
    }

    /**
     * Money in, money out, net injected, and how that squares with the trades.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function reconcile(Portfolio $portfolio, array $rows): array
    {
        $totals = CashStatementReconciler::totals($rows);

        // What the trade ledger says the trades consumed: buys plus fees, less
        // sale proceeds and dividends. `PerformanceService::cashFlow()` is
        // public static precisely so a second subsystem cannot re-derive the
        // sign convention and drift from it.
        $trades = PortfolioTransaction::where('portfolio_id', $portfolio->id)->get();
        $impliedCapital = $trades->isEmpty()
            ? null
            : round($trades->sum(
                static fn (PortfolioTransaction $t): float => -PerformanceService::cashFlow($t),
            ), 2);

        $summary = $this->valuation->summarize($portfolio);

        // A portfolio holding something we cannot price has no honest total,
        // so profit reports null rather than a figure that quietly omits it.
        $closing = ($summary['unpriced_symbols'] ?? []) === []
            ? (float) $summary['net_worth']
            : null;

        return CashStatementReconciler::reconcile($totals, $impliedCapital, $closing);
    }

    /**
     * Counts for the review UI.
     *
     * @return array<string, mixed>
     */
    /**
     * The summary plus the staged rows themselves.
     *
     * Review is the whole point of staging — "43 rows ready" tells a user
     * nothing they can act on. Kept separate from {@see summary()} so the list
     * endpoint does not ship every row of every past import.
     *
     * @return array<string, mixed>
     */
    public function detail(CashStatementImport $import): array
    {
        return $this->summary($import) + ['rows' => array_values($import->merged_rows ?? [])];
    }

    private function resolveAccount(?string $account): string
    {
        $account ??= (string) config('nepse.cash_import.default_account', CashTransaction::ACCOUNT_BANK);

        if (! in_array($account, CashTransaction::accounts(), true)) {
            throw new RuntimeException("Unknown cash account [{$account}].");
        }

        return $account;
    }

    public function summary(CashStatementImport $import): array
    {
        $rows = collect($import->merged_rows ?? []);
        $totals = CashStatementReconciler::totals($rows->all());

        return [
            'format' => $import->format,
            'account' => $import->account,
            'total_rows' => $rows->count(),
            'ready' => $rows->where('status', self::ROW_READY)->count(),
            'excluded_failed' => $rows->where('status', self::ROW_EXCLUDED_FAILED)->count(),
            'duplicate' => $rows->where('status', self::ROW_DUPLICATE)->count(),
            'needs_review' => $rows->where('status', self::ROW_NEEDS_REVIEW)->count(),
            'overlap_warning' => $rows->where('status', self::ROW_OVERLAP_WARNING)->count(),
            'included' => $rows->where('include', true)->count(),
            'money_in' => $totals['money_in'],
            'money_out' => $totals['money_out'],
            'net_injected' => $totals['net_injected'],
            'deposits' => $totals['deposits'],
            'withdrawals' => $totals['withdrawals'],
            'first_movement_on' => $totals['first_movement_on'],
            'last_movement_on' => $totals['last_movement_on'],
            'excluded' => $totals['excluded'],
            'overlap' => $import->overlap,
            /*
             * Contributed capital, apart from the gross movement. This is the
             * figure the statement actually exists to establish: money you put
             * in and took out. Settlements move cash against shares you already
             * own and are not new money, so they are staged (the balance only
             * reconciles with them) but excluded from this.
             *
             * Null when the format cannot label its rows — see the reconciler.
             */
            'capital_in' => $totals['capital_in'],
            'capital_out' => $totals['capital_out'],
            'net_capital' => $totals['net_capital'],
            'filename' => $import->filename,
            'status' => $import->status,
            'id' => $import->id,
            'reconciliation' => $import->reconciliation,
            'warnings' => $import->warnings ?? [],
        ];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Movements already committed for this portfolio, as the overlap detector
     * wants them.
     *
     * @return list<array{fingerprint: string, occurred_on: string, amount: float, direction: string, import_id: int|null}>
     */
    /**
     * Money already in this portfolio's ledger, whatever put it there.
     *
     * Two sources, and the second is not optional. `cash_statement_entries`
     * records what *this wizard* has committed, so on its own it is blind to
     * every movement that arrived another way — the `nepse:broker-import`
     * command, a hand-entered deposit, a restored backup. A hypothetical
     * duplicate import could offer every statement row already present in
     * the ledger, which is exactly the silent double-count
     * {@see CashStatementOverlapDetector} exists to prevent.
     *
     * Ledger rows carry no fingerprint, so they can only ever match on the
     * detector's second tier: same day, same amount, same direction. That tier
     * excludes by default and says why, which is the right way round — a row
     * wrongly held back is a visible gap the user clears in one click, while a
     * row wrongly written is a wrong answer nobody sees.
     *
     * @return list<array{fingerprint: string, occurred_on: string, amount: float, direction: string, import_id?: int|string|null}>
     */
    private function committedEntries(CashStatementImport $import): array
    {
        $entries = CashStatementEntry::query()
            ->where('portfolio_id', $import->portfolio_id)
            // A row this import itself committed is not something to warn
            // about; only other imports count as "already there".
            ->where(static function ($query) use ($import): void {
                $query->whereNull('cash_statement_import_id')
                    ->orWhere('cash_statement_import_id', '!=', $import->id);
            })
            ->get()
            ->map(static fn (CashStatementEntry $entry): array => [
                'fingerprint' => (string) $entry->fingerprint,
                'occurred_on' => $entry->occurred_on->toDateString(),
                'amount' => (float) $entry->amount,
                'direction' => (string) $entry->direction,
                'import_id' => $entry->cash_statement_import_id,
            ])
            ->all();

        $ledger = CashTransaction::query()
            ->where('portfolio_id', $import->portfolio_id)
            ->where('account', $import->account)
            ->whereNull('voided_at')
            /*
             * IPO funding and its paired debit are synthetic: they represent
             * money that moved between the applicant's bank and their shares,
             * never across the broker account. A statement row can never be a
             * re-import of one, and leaving them in would flag a genuine
             * deposit as a duplicate whenever it happened to match one by date
             * and amount.
             */
            ->whereNotIn('type', IpoCapitalService::syntheticTypes())
            ->get()
            ->map(static function (CashTransaction $row): array {
                $amount = (float) $row->amount;

                return [
                    // Deliberately unmatchable as a fingerprint: a ledger row
                    // has no movement id, so it must never satisfy tier one.
                    'fingerprint' => 'ledger:'.$row->id,
                    'occurred_on' => Carbon::parse((string) $row->transacted_on)->toDateString(),
                    'amount' => abs($amount),
                    'direction' => $amount < 0
                        ? CashStatementParser::DIRECTION_OUT
                        : CashStatementParser::DIRECTION_IN,
                    'import_id' => null,
                ];
            })
            ->all();

        return [...$entries, ...$ledger];
    }

    /**
     * Final row status, given what the parser said and what the ledger holds.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $overlap
     * @return array<string, mixed>
     */
    private function classify(array $row, array $overlap): array
    {
        $fingerprint = $row['fingerprint'] ?? null;

        // A failed movement stays excluded whatever else is true of it: no
        // money moved, so there is nothing to import or to duplicate.
        if (($row['status'] ?? null) === CashStatementParser::ROW_EXCLUDED_FAILED) {
            $row['status'] = self::ROW_EXCLUDED_FAILED;
            $row['include'] = false;

            return $row;
        }

        if ($fingerprint !== null && isset($overlap['duplicates'][$fingerprint])) {
            $row['status'] = self::ROW_DUPLICATE;
            $row['include'] = false;
            $row['reason'] = $overlap['duplicates'][$fingerprint];

            return $row;
        }

        if ($fingerprint !== null && isset($overlap['collisions'][$fingerprint])) {
            $row['status'] = self::ROW_OVERLAP_WARNING;
            // Excluded by default. A missed row is a visible gap in the
            // reconciliation; a doubled row is a wrong answer nobody sees.
            $row['include'] = (bool) ($row['edited'] ?? false) && (bool) ($row['include'] ?? false);
            $row['reason'] = $overlap['collisions'][$fingerprint];

            return $row;
        }

        if (($row['status'] ?? null) === CashStatementParser::ROW_NEEDS_REVIEW) {
            $row['status'] = self::ROW_NEEDS_REVIEW;

            return $row;
        }

        $row['status'] = self::ROW_READY;

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function applyEdit(array $row, array $changes): array
    {
        if (array_key_exists('direction', $changes) && $changes['direction'] !== null) {
            $direction = (string) $changes['direction'];

            if (! in_array($direction, [CashStatementParser::DIRECTION_IN, CashStatementParser::DIRECTION_OUT], true)) {
                throw new RuntimeException("Direction must be 'in' or 'out'.");
            }

            $row['direction'] = $direction;
        }

        if (array_key_exists('amount', $changes) && $changes['amount'] !== null) {
            $amount = round((float) $changes['amount'], 2);

            if ($amount <= 0.0) {
                throw new RuntimeException('An amount must be greater than zero.');
            }

            $row['amount'] = $amount;
        }

        if (array_key_exists('occurred_on', $changes) && $changes['occurred_on'] !== null) {
            $date = (string) $changes['occurred_on'];

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new RuntimeException('A date must be given as YYYY-MM-DD.');
            }

            $row['occurred_on'] = $date;
        }

        if (array_key_exists('reference', $changes)) {
            $row['reference'] = $changes['reference'] === null ? null : (string) $changes['reference'];
        }

        if (array_key_exists('include', $changes)) {
            $row['include'] = (bool) $changes['include'];
        }

        $row['edited'] = true;

        // The identity and the sign follow from the fields, so they are
        // re-derived rather than left stale — a row that gains a direction in
        // review gains a fingerprint with it, and becomes importable.
        $row['fingerprint'] = $this->parser->fingerprint(
            $row['external_id'] ?? null,
            $row['occurred_on'] ?? null,
            $row['amount'] === null ? null : (float) $row['amount'],
            $row['direction'] ?? null,
            $row['reference'] ?? null,
        );

        $row['signed_amount'] = ($row['amount'] ?? null) === null || ($row['direction'] ?? null) === null
            ? null
            : ((string) $row['direction'] === CashStatementParser::DIRECTION_OUT
                ? -(float) $row['amount']
                : (float) $row['amount']);

        // A row the user has completed is no longer waiting on them.
        if (($row['status'] ?? null) === CashStatementParser::ROW_NEEDS_REVIEW
            && $row['fingerprint'] !== null
            && ($row['amount'] ?? null) !== null) {
            $row['status'] = CashStatementParser::ROW_READY;
            $row['reason'] = null;

            if (! array_key_exists('include', $changes)) {
                $row['include'] = true;
            }
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function ledgerNote(CashStatementImport $import, array $row): string
    {
        $parts = ['Imported from broker cash statement'];

        if ($import->filename !== null && $import->filename !== '') {
            $parts[] = "({$import->filename})";
        }

        $narration = (string) ($row['narration'] ?? '');

        if ($narration !== '') {
            // Already redacted at parse time — no names, no account numbers,
            // no institutions.
            $parts[] = '— '.$narration;
        }

        return implode(' ', $parts).'.';
    }

    /**
     * The day before the earliest movement, so an opening balance sorts ahead
     * of everything the statement itself contains.
     *
     * @param  list<array<string, mixed>>  $ordered
     */
    private function openingDate(array $ordered): Carbon
    {
        $first = $ordered[0]['occurred_on'] ?? null;

        return $first === null
            ? Carbon::today()
            : Carbon::parse((string) $first)->subDay();
    }

    private function assertEditable(CashStatementImport $import): void
    {
        if (! $import->isEditable()) {
            throw new RuntimeException("This import is already {$import->status} and cannot be changed.");
        }
    }
}
