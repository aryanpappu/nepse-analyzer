<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Enums\AcquisitionChannel;
use App\Models\CashTransaction;
use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Record the money an IPO or rights subscription cost, which no broker
 * statement can explain.
 *
 * An IPO application is paid straight from the applicant's bank through
 * MeroShare/CASBA. The shares arrive; the money that bought them appears in no
 * broker record at all. In a fictional example, 12,000.00 of cost basis sits
 * outside the broker statement alongside 58,000.00 of broker-traded cost. So a portfolio built from a
 * MeroShare import counts those shares as value while counting none of their
 * cost, and reports a profit that is overstated by the difference.
 *
 * ## Derived, never stored
 *
 * Whether a transaction's capital has been recorded is decided by looking for
 * the ledger row, exactly as {@see CashPlacementSettlement} decides whether a
 * deposit was settled. A boolean column would be a second source of truth able
 * to disagree with the ledger; voiding the row would leave it lying. Here,
 * removing the row *is* the untick.
 *
 * That is what makes the round trip the user asked for exact: ticking IPO
 * writes one row, unticking deletes it, and re-ticking writes one again —
 * never two, and never a residue.
 *
 * ## It never invents a number
 *
 * The capital recorded is quantity times the price already on the transaction,
 * which is what the user paid. If that price is zero the amount is unknowable
 * and nothing is written — a bonus issue costs nothing, and a zero-priced row
 * is missing data rather than a free share.
 */
final class IpoCapitalService
{
    /** Marks a ledger row as this service's, and ties it to one transaction. */
    private const REFERENCE_PREFIX = 'ipo-capital:';

    /** The funding leg's own prefix, so the pair is addressable separately. */
    private const FUNDING_REFERENCE_PREFIX = 'ipo-funding:';

    /**
     * Its own ledger type, so it can never be confused with a deposit the user
     * really made or with a broker trade settlement.
     */
    public const TYPE = 'subscription_debit';

    /**
     * The money arriving from the applicant's own bank to pay for the
     * subscription.
     *
     * Written as a pair with {@see TYPE}, same date, same amount, one
     * `transfer_group_id`. Without it the debit alone was simply wrong about
     * the broker account: it removed money that had never been in it, and the
     * balance would be understated by the whole subscription total. In a
     * fictional example, subtracting 3,000.00 from a 5,000.00 balance would
     * incorrectly show only 2,000.00.
     *
     * The pair nets to zero in the balance and says the true thing: money came
     * in from outside, and immediately turned into shares. It is the funding
     * leg, not the debit, that counts as contributed capital — the debit is an
     * internal transfer of cash into shares, exactly like a buy settlement.
     */
    public const FUNDING_TYPE = 'subscription_funding';

    public function __construct(
        private readonly CashLedger $ledger,
    ) {}

    public static function referenceFor(int $transactionId): string
    {
        return self::REFERENCE_PREFIX.$transactionId;
    }

    public static function fundingReferenceFor(int $transactionId): string
    {
        return self::FUNDING_REFERENCE_PREFIX.$transactionId;
    }

    /** Both synthetic types, for callers that must exclude them wholesale. */
    public static function syntheticTypes(): array
    {
        return [self::TYPE, self::FUNDING_TYPE];
    }

    /**
     * Whether the funding leg for a transaction is present.
     *
     * Separate from {@see isRecorded()} because the two legs were not always
     * written together: rows created before the pairing existed have a debit
     * and no funding, and {@see reconcile()} repairs exactly that case.
     */
    public function hasFunding(Portfolio $portfolio, int $transactionId): bool
    {
        return DB::table('cash_transactions')
            ->where('portfolio_id', $portfolio->id)
            ->where('type', self::FUNDING_TYPE)
            ->whereNull('voided_at')
            ->where('reference', self::fundingReferenceFor($transactionId))
            ->exists();
    }

    /**
     * Which of these transactions already have their capital recorded.
     *
     * One query, keyed for O(1) lookup — a serializer must never ask per row.
     *
     * @param  list<int>  $transactionIds
     * @return array<int, true>
     */
    public function recordedFor(Portfolio $portfolio, array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        $references = array_map(
            static fn (int $id): string => self::referenceFor($id),
            $transactionIds,
        );

        $found = DB::table('cash_transactions')
            ->where('portfolio_id', $portfolio->id)
            ->where('type', self::TYPE)
            ->whereNull('voided_at')
            ->whereIn('reference', $references)
            ->pluck('reference')
            ->all();

        $recorded = [];

        foreach ($found as $reference) {
            $recorded[(int) substr((string) $reference, strlen(self::REFERENCE_PREFIX))] = true;
        }

        return $recorded;
    }

    public function isRecorded(Portfolio $portfolio, PortfolioTransaction $transaction): bool
    {
        return isset($this->recordedFor($portfolio, [(int) $transaction->id])[(int) $transaction->id]);
    }

    /**
     * The capital a transaction represents, or null when it cannot be known.
     *
     * Null and zero are different answers: null means the price was never
     * recorded, zero would claim the shares were free.
     */
    public function capitalFor(PortfolioTransaction $transaction): ?float
    {
        $quantity = round((float) $transaction->quantity, 2);
        $price = round((float) $transaction->price, 4);

        if ($quantity <= 0.0 || $price <= 0.0) {
            return null;
        }

        return round($quantity * $price, 2);
    }

    /**
     * Record the subscription money for a transaction, once.
     *
     * Idempotent: called twice, the second call is a no-op rather than a second
     * row. Returns null when there was nothing to record or it was already
     * recorded, so a caller can report honestly either way.
     */
    public function record(
        Portfolio $portfolio,
        PortfolioTransaction $transaction,
        string $account = CashTransaction::ACCOUNT_BANK,
    ): ?CashTransaction {
        $channel = AcquisitionChannel::parse($transaction->acquisition_channel);

        if (! $channel->fundedOutsideBroker()) {
            throw new RuntimeException(sprintf(
                'Only a channel funded outside the broker carries its own capital; %s does not.',
                $channel->label(),
            ));
        }

        if ($this->isRecorded($portfolio, $transaction)) {
            return null;
        }

        $amount = $this->capitalFor($transaction);

        if ($amount === null) {
            return null;
        }

        $symbol = $transaction->stock->symbol ?? 'unknown scrip';
        // Cast to a date on the model, but normalise here so the call site
        // proves the type rather than relying on the cast.
        $date = Carbon::parse($transaction->transacted_on);
        $group = (string) Str::uuid();

        return DB::transaction(function () use (
            $portfolio, $transaction, $account, $amount, $channel, $symbol, $date, $group
        ): CashTransaction {
            /*
             * The funding leg goes first, and the order matters: it is what
             * makes the debit that follows unable to overdraw an account it
             * was never really spending from.
             */
            $this->writeFunding($portfolio, $transaction, $account, $amount, $symbol, $date, $group);

            return $this->ledger->record(
                $portfolio,
                $account,
                -$amount,
                self::TYPE,
                self::referenceFor((int) $transaction->id),
                sprintf(
                    '%s subscription paid outside the broker (%s)',
                    $channel->shortLabel(),
                    $symbol,
                ),
                $date,
                $group,
                // A back-dated subscription can still precede the deposits that
                // would have funded it. The money genuinely left the applicant's
                // bank, and refusing it would leave the cost unrecorded.
                true,
            );
        });
    }

    /**
     * Write the funding leg on its own.
     *
     * Public-ish via {@see reconcile()} rather than only from {@see record()},
     * because a ledger written before the pairing existed has debits with no
     * partner and needs them added without disturbing the debit itself.
     */
    private function writeFunding(
        Portfolio $portfolio,
        PortfolioTransaction $transaction,
        string $account,
        float $amount,
        string $symbol,
        Carbon $date,
        string $group,
    ): CashTransaction {
        return $this->ledger->record(
            $portfolio,
            $account,
            $amount,
            self::FUNDING_TYPE,
            self::fundingReferenceFor((int) $transaction->id),
            sprintf('Transferred from your bank to pay for the %s subscription', $symbol),
            $date,
            $group,
            true,
        );
    }

    /**
     * Remove the capital row again — the untick.
     *
     * Hard-deleted rather than voided: a voided row stays in the ledger as an
     * explanation of something the user really did, whereas this row only ever
     * existed as a consequence of a flag. Leaving a tombstone behind every time
     * a checkbox is toggled would make the ledger unreadable.
     */
    public function remove(
        Portfolio $portfolio,
        PortfolioTransaction $transaction,
        bool $recompute = true,
    ): int {
        // Both legs, or the untick would leave the funding deposit behind and
        // credit the account with money that bought nothing.
        $removed = DB::table('cash_transactions')
            ->where('portfolio_id', $portfolio->id)
            ->whereIn('type', self::syntheticTypes())
            ->whereIn('reference', [
                self::referenceFor((int) $transaction->id),
                self::fundingReferenceFor((int) $transaction->id),
            ])
            ->delete();

        if ($removed > 0 && $recompute) {
            // Balances are a function of the ledger, so they are replayed
            // rather than adjusted by the amount just removed.
            $this->ledger->recompute($portfolio);
        }

        return $removed;
    }

    /**
     * Bring the ledger into line with the channels currently set.
     *
     * Adds what is missing for IPO and rights rows, removes what is left over
     * on rows that are no longer either. Safe to run repeatedly — that is the
     * point, since a user can toggle a channel any number of times.
     *
     * @return array{recorded: int, removed: int, paired: int, skipped_without_price: list<string>}
     */
    public function reconcile(Portfolio $portfolio, string $account = CashTransaction::ACCOUNT_BANK): array
    {
        $recorded = 0;
        $removed = 0;
        $paired = 0;
        $skipped = [];

        $transactions = PortfolioTransaction::with('stock')
            ->where('portfolio_id', $portfolio->id)
            ->get();

        $already = $this->recordedFor(
            $portfolio,
            $transactions->map(static fn (PortfolioTransaction $t): int => (int) $t->id)->all(),
        );

        foreach ($transactions as $transaction) {
            $channel = AcquisitionChannel::parse($transaction->acquisition_channel);
            $has = isset($already[(int) $transaction->id]);

            if ($channel->fundedOutsideBroker()) {
                if ($has) {
                    /*
                     * Repair, not a no-op. Debits written before the funding
                     * leg existed have no partner, and left alone they go on
                     * understating the account by their own amount forever.
                     * Reconcile is the one thing a user is told to re-run, so
                     * it is where the repair belongs.
                     */
                    if (! $this->hasFunding($portfolio, (int) $transaction->id)) {
                        $amount = $this->capitalFor($transaction);

                        if ($amount !== null) {
                            $this->writeFunding(
                                $portfolio,
                                $transaction,
                                $account,
                                $amount,
                                (string) ($transaction->stock->symbol ?? 'unknown scrip'),
                                Carbon::parse($transaction->transacted_on),
                                (string) Str::uuid(),
                            );
                            $paired++;
                        }
                    }

                    continue;
                }

                if ($this->capitalFor($transaction) === null) {
                    $skipped[] = (string) ($transaction->stock->symbol ?? $transaction->id);

                    continue;
                }

                $this->record($portfolio, $transaction, $account);
                $recorded++;

                continue;
            }

            if ($has) {
                $removed += $this->remove($portfolio, $transaction, false);
            }
        }

        /*
         * One replay, after every change — never one per row.
         *
         * Repairing a ledger of unpaired debits means the balance is wrong for
         * as long as the loop is running, and `recomputeAccount()` refuses a
         * replay that overdraws. Recomputing inside the loop therefore threw on
         * a half-repaired ledger ("would overdraw by NPR 2,296.81") while the
         * finished state is perfectly valid: each pair nets to zero on its own
         * date, so the completed replay matches the ledger as it stood before
         * any subscription was recorded at all.
         */
        if ($recorded > 0 || $removed > 0 || $paired > 0) {
            $this->ledger->recompute($portfolio);
        }

        return [
            'recorded' => $recorded,
            'removed' => $removed,
            // Funding legs added to debits that predate the pairing.
            'paired' => $paired,
            'skipped_without_price' => array_values(array_unique($skipped)),
        ];
    }

    /**
     * What this service has added, so a report can say how much of the capital
     * base came from outside the broker rather than presenting it unlabelled.
     *
     * @return array{rows: int, capital: float}
     */
    public function summary(Portfolio $portfolio): array
    {
        $rows = DB::table('cash_transactions')
            ->where('portfolio_id', $portfolio->id)
            ->where('type', self::TYPE)
            ->whereNull('voided_at')
            ->get(['amount']);

        return [
            'rows' => $rows->count(),
            'capital' => round((float) $rows->sum(static fn (object $r): float => abs((float) $r->amount)), 2),
        ];
    }
}
