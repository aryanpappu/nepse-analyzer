<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Models\CashTransaction;
use App\Models\Portfolio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Records cash movements and keeps the portfolio's two balance columns in step
 * with the ledger. Every mutation goes through here so the balance can always
 * be reconciled against the sum of its transactions.
 *
 * Correcting a mis-entered movement (amend()/void()) never patches
 * `cash_in_hand`/`bank_balance` directly — like `HoldingCalculator` treats a
 * position as a function of the transaction ledger, these balances are always
 * recomputed by replaying the surviving (non-voided) rows for the affected
 * account. That is what keeps `balance_after` on every later row correct
 * instead of stale.
 */
final class CashLedger
{
    /**
     * @param  string  $account  cash|bank
     * @param  float  $amount  Signed: positive credits, negative debits.
     */
    public function record(
        Portfolio $portfolio,
        string $account,
        float $amount,
        string $type,
        ?string $reference = null,
        ?string $notes = null,
        ?Carbon $date = null,
        ?string $transferGroupId = null,
        bool $allowOverdraft = false,
    ): CashTransaction {
        if (! in_array($account, CashTransaction::accounts(), true)) {
            throw new RuntimeException("Unknown cash account [{$account}].");
        }

        $amount = round($amount, 2);

        if (abs($amount) < 0.01) {
            throw new RuntimeException('Cash transaction amount must be non-zero.');
        }

        return DB::transaction(function () use ($portfolio, $account, $amount, $type, $reference, $notes, $date, $transferGroupId, $allowOverdraft): CashTransaction {
            // Re-read under a lock: the balance we validate against must be the
            // one we write, or two concurrent withdrawals could both pass.
            $locked = Portfolio::whereKey($portfolio->id)->lockForUpdate()->firstOrFail();

            $column = CashTransaction::balanceColumn($account);
            $balance = round((float) $locked->{$column}, 2);
            $newBalance = round($balance + $amount, 2);

            /*
             * This guard stops a user spending money they do not have. It is
             * bypassable for exactly one case: importing a broker statement
             * that genuinely went into debit. Brokers settle T+2 and extend
             * credit in between — a fictional statement could show 250.00 DR on
             * 2001-01-15, funded by a deposit on 2001-01-18. Refusing that
             * would make it impossible to record history as it happened, and a
             * ledger that cannot state the truth is worse than one that admits
             * a transient debit.
             *
             * Never pass this for a user-initiated movement.
             */
            if ($newBalance < 0 && ! $allowOverdraft) {
                throw new RuntimeException(sprintf(
                    'Insufficient %s balance: NPR %s available, NPR %s requested.',
                    $account === CashTransaction::ACCOUNT_BANK ? 'bank' : 'cash',
                    number_format($balance, 2),
                    number_format(abs($amount), 2),
                ));
            }

            $locked->{$column} = $newBalance;
            $locked->save();

            // Keep the caller's instance in step so the response is accurate.
            $portfolio->{$column} = $newBalance;

            return CashTransaction::create([
                'portfolio_id' => $locked->id,
                'type' => $type,
                'account' => $account,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reference' => $reference,
                'notes' => $notes,
                'transacted_on' => ($date ?? Carbon::today())->toDateString(),
                'transfer_group_id' => $transferGroupId,
            ]);
        });
    }

    public function deposit(
        Portfolio $portfolio,
        string $account,
        float $amount,
        ?string $reference = null,
        ?string $notes = null,
        ?Carbon $date = null,
    ): CashTransaction {
        return $this->record($portfolio, $account, abs($amount), 'deposit', $reference, $notes, $date);
    }

    public function withdraw(
        Portfolio $portfolio,
        string $account,
        float $amount,
        ?string $reference = null,
        ?string $notes = null,
        ?Carbon $date = null,
    ): CashTransaction {
        return $this->record($portfolio, $account, -abs($amount), 'withdrawal', $reference, $notes, $date);
    }

    /**
     * Move money between the two accounts as a single atomic pair.
     *
     * The two rows are stamped with a shared `transfer_group_id` so that
     * amending or voiding one leg later can safely apply to both — leaving
     * only one leg corrected would silently unbalance the transfer.
     *
     * @return array{from: CashTransaction, to: CashTransaction}
     */
    public function transfer(Portfolio $portfolio, string $from, string $to, float $amount, ?Carbon $date = null): array
    {
        if ($from === $to) {
            throw new RuntimeException('Transfer source and destination must differ.');
        }

        $groupId = (string) Str::uuid();

        return DB::transaction(fn (): array => [
            'from' => $this->record($portfolio, $from, -abs($amount), 'transfer', null, "Transfer to {$to}", $date, $groupId),
            'to' => $this->record($portfolio, $to, abs($amount), 'transfer', null, "Transfer from {$from}", $date, $groupId),
        ]);
    }

    /**
     * Set a balance outright, writing the difference as an adjusting entry so
     * the ledger still explains the new figure.
     */
    public function setBalance(
        Portfolio $portfolio,
        string $account,
        float $target,
        ?string $notes = null,
        ?Carbon $date = null,
    ): ?CashTransaction {
        $column = CashTransaction::balanceColumn($account);
        $current = round((float) $portfolio->{$column}, 2);
        $delta = round($target - $current, 2);

        if (abs($delta) < 0.01) {
            return null;
        }

        return $this->record(
            $portfolio,
            $account,
            $delta,
            $delta > 0 ? 'deposit' : 'withdrawal',
            null,
            $notes ?? 'Manual balance adjustment',
            $date,
        );
    }

    /**
     * Amend a mis-entered transaction's amount, date, account, reference
     * and/or notes in place, rather than offsetting it with a second entry.
     *
     * The row's pre-edit values are preserved (once, on first edit) in
     * `original_values` so the correction stays auditable, and every later
     * `balance_after` for the affected account(s) is recomputed from the
     * surviving ledger — never patched.
     *
     * @param  array<string, mixed>  $changes  Any of: amount, account, transacted_on, reference, notes.
     */
    public function amend(Portfolio $portfolio, CashTransaction $transaction, array $changes): CashTransaction
    {
        $this->assertBelongsTo($portfolio, $transaction);

        if ($transaction->isVoided()) {
            throw new RuntimeException('Cannot amend a voided transaction — it no longer counts toward the balance.');
        }

        if ($transaction->transfer_group_id !== null && array_key_exists('account', $changes)) {
            throw new RuntimeException(
                'A transfer leg cannot be reassigned to a different account; void the transfer and re-enter it.',
            );
        }

        return DB::transaction(function () use ($portfolio, $transaction, $changes): CashTransaction {
            $locked = Portfolio::whereKey($portfolio->id)->lockForUpdate()->firstOrFail();
            $rows = $this->lockedGroup($transaction);

            /** @var array<string, true> $accountsTouched */
            $accountsTouched = [];

            foreach ($rows as $row) {
                $original = [
                    'amount' => (float) $row->amount,
                    'account' => $row->account,
                    'reference' => $row->reference,
                    'notes' => $row->notes,
                    'transacted_on' => $row->transacted_on->toDateString(),
                ];

                if (array_key_exists('account', $changes)) {
                    if (! in_array($changes['account'], CashTransaction::accounts(), true)) {
                        throw new RuntimeException("Unknown cash account [{$changes['account']}].");
                    }

                    $accountsTouched[$row->account] = true;
                    $row->account = $changes['account'];
                }

                if (array_key_exists('amount', $changes)) {
                    $row->amount = $this->signedAmount($row, (float) $changes['amount']);
                }

                if (array_key_exists('transacted_on', $changes) && $changes['transacted_on'] !== null) {
                    $row->transacted_on = Carbon::parse($changes['transacted_on'])->toDateString();
                }

                if (array_key_exists('reference', $changes)) {
                    $row->reference = $changes['reference'];
                }

                if (array_key_exists('notes', $changes)) {
                    $row->notes = $changes['notes'];
                }

                // Preserve the true original across possibly repeated edits —
                // only ever set once.
                if ($row->original_values === null) {
                    $row->original_values = $original;
                }

                $row->corrected_at = Carbon::now();
                $row->save();

                $accountsTouched[$row->account] = true;
            }

            foreach (array_keys($accountsTouched) as $account) {
                $this->recomputeAccount($locked, $account);
            }

            return $transaction->fresh();
        });
    }

    /**
     * Void (soft-delete) a transaction entered entirely in error.
     *
     * The row is kept — marked `voided_at`/`void_reason` — rather than
     * hard-deleted, so a fat-fingered entry is still visible if a user asks
     * why the balance is what it is, but it is excluded from the running
     * balance and from the default ledger listing so it does not read as
     * permanent noise.
     */
    public function void(Portfolio $portfolio, CashTransaction $transaction, ?string $reason = null): CashTransaction
    {
        $this->assertBelongsTo($portfolio, $transaction);

        if ($transaction->isVoided()) {
            return $transaction;
        }

        return DB::transaction(function () use ($portfolio, $transaction, $reason): CashTransaction {
            $locked = Portfolio::whereKey($portfolio->id)->lockForUpdate()->firstOrFail();
            $rows = $this->lockedGroup($transaction);

            /** @var array<string, true> $accountsTouched */
            $accountsTouched = [];

            foreach ($rows as $row) {
                $row->voided_at = Carbon::now();
                $row->void_reason = $reason ?? 'Entered in error.';
                $row->save();

                $accountsTouched[$row->account] = true;
            }

            foreach (array_keys($accountsTouched) as $account) {
                $this->recomputeAccount($locked, $account);
            }

            return $transaction->fresh();
        });
    }

    /**
     * Pure function: replay an ordered, signed-amount ledger and return each
     * row's running balance plus the ending balance. No DB access — this is
     * the core the unit tests exercise directly.
     *
     * @param  list<array{id: int|string, amount: float}>  $rows  Ordered oldest-first.
     * @return array{balances: array<int|string, float>, ending: float}
     */
    public static function replay(array $rows): array
    {
        $balance = 0.0;
        $balances = [];

        foreach ($rows as $row) {
            $balance = round($balance + round((float) $row['amount'], 2), 2);
            $balances[$row['id']] = $balance;
        }

        return ['balances' => $balances, 'ending' => $balance];
    }

    /**
     * Reload every non-voided row for one account, replay it, write the
     * recomputed `balance_after` back onto each row, and set the portfolio's
     * running balance column to the replay's ending balance.
     */
    /**
     * Replay both accounts from the ledger.
     *
     * For callers that delete or restore a row directly rather than going
     * through amend()/void() — {@see IpoCapitalService::remove()} removes the
     * capital row behind an untick, and the balances have to follow. Public
     * because the alternative is each such caller adjusting a balance by the
     * amount it happened to remove, which is exactly the patch-instead-of-replay
     * this class exists to prevent.
     */
    public function recompute(Portfolio $portfolio): void
    {
        foreach (CashTransaction::accounts() as $account) {
            $this->recomputeAccount($portfolio, $account);
        }
    }

    private function recomputeAccount(Portfolio $portfolio, string $account): void
    {
        $rows = CashTransaction::query()
            ->where('portfolio_id', $portfolio->id)
            ->where('account', $account)
            ->notVoided()
            ->orderBy('transacted_on')
            ->orderBy('id')
            ->get(['id', 'amount']);

        $result = self::replay(
            $rows->map(static fn (CashTransaction $row): array => [
                'id' => $row->id,
                'amount' => (float) $row->amount,
            ])->all(),
        );

        $column = CashTransaction::balanceColumn($account);

        /*
         * record() refuses any movement that would overdraw an account, so a
         * correction must not be able to sneak past the same rule: amending a
         * 500k deposit down to 5k after 400k has been spent, or voiding a
         * deposit that later withdrawals relied on, would otherwise leave a
         * negative balance column that no deposit path could have produced.
         * Throwing here rolls back the surrounding transaction, so the ledger
         * is left exactly as it was.
         */
        if ($result['ending'] < -0.005) {
            throw new RuntimeException(sprintf(
                'That correction would overdraw the %s account by NPR %s. '
                .'Void or amend the movements that depend on it first.',
                $account === CashTransaction::ACCOUNT_BANK ? 'bank' : 'cash',
                number_format(abs($result['ending']), 2),
            ));
        }

        foreach ($result['balances'] as $id => $balance) {
            CashTransaction::whereKey($id)->update(['balance_after' => $balance]);
        }

        $portfolio->{$column} = $result['ending'];
        $portfolio->save();
    }

    /**
     * A transaction on its own, or — for a transfer leg — both rows that
     * share its `transfer_group_id`, locked for update so a concurrent
     * amend/void on the sibling leg cannot interleave.
     *
     * @return Collection<int, CashTransaction>
     */
    private function lockedGroup(CashTransaction $transaction): Collection
    {
        if ($transaction->transfer_group_id === null) {
            /** @var CashTransaction $locked */
            $locked = CashTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            return collect([$locked]);
        }

        return CashTransaction::where('transfer_group_id', $transaction->transfer_group_id)
            ->lockForUpdate()
            ->get();
    }

    /**
     * The sign convention a corrected amount should take: deposits and trade
     * credits are always positive, withdrawals and trade debits are always
     * negative, and anything else (transfer legs, dividends, ...) keeps
     * whichever sign the row already had.
     */
    private function signedAmount(CashTransaction $transaction, float $magnitude): float
    {
        $magnitude = round(abs($magnitude), 2);

        return match ($transaction->type) {
            'deposit', 'trade_credit' => $magnitude,
            'withdrawal', 'trade_debit' => -$magnitude,
            default => (float) $transaction->amount < 0 ? -$magnitude : $magnitude,
        };
    }

    private function assertBelongsTo(Portfolio $portfolio, CashTransaction $transaction): void
    {
        if ((int) $transaction->portfolio_id !== (int) $portfolio->id) {
            throw new RuntimeException('That cash transaction does not belong to this portfolio.');
        }
    }
}
