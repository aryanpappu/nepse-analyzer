<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Enums\AcquisitionChannel;
use App\Enums\TransactionType;
use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use App\Support\NepaliFiscalYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Answers "am I actually making money, and when did I make it".
 *
 * Three things the portfolio summary could not tell you: what each year earned,
 * whether the winners outnumber the losers, and what annual rate the whole
 * thing has compounded at once deposits and withdrawals are accounted for.
 *
 * Realised figures come from the transaction ledger, so they are historical
 * fact. Unrealised figures are marked at the latest close and belong to the
 * current period only — there is no bar-by-bar mark-to-market history here, so
 * a closed year reports what it realised, not what it was briefly worth.
 */
final class PerformanceService
{
    /** Buckets by Gregorian calendar year. */
    public const BASIS_CALENDAR = 'calendar';

    /** Buckets by Nepali fiscal year, Shrawan to Ashad. */
    public const BASIS_FISCAL = 'fiscal';

    public function __construct(
        private readonly PortfolioValuationService $valuation,
        private readonly PortfolioTimeSeries $timeSeries = new PortfolioTimeSeries,
    ) {}

    /**
     * @return array<string, mixed>
     */
    /** Overrides the configured deposit rate for one report. */
    private ?float $rateOverride = null;

    public function report(
        Portfolio $portfolio,
        string $basis = self::BASIS_FISCAL,
        ?float $fixedDepositRate = null,
    ): array {
        $this->rateOverride = $fixedDepositRate;

        $basis = in_array($basis, [self::BASIS_CALENDAR, self::BASIS_FISCAL], true)
            ? $basis
            : self::BASIS_FISCAL;

        $transactions = $portfolio->transactions()
            ->with('stock:id,symbol')
            ->orderBy('transacted_on')
            ->orderBy('id')
            ->get();

        $trades = $this->closedTrades($transactions);
        $summary = $this->valuation->summarize($portfolio);

        return [
            'basis' => $basis,
            'fixed_deposit_rate' => $this->fixedDepositRate(null),
            // Every "vs FD" figure in this payload, per-period and lifetime,
            // compares the equity book against a hypothetical deposit. Real
            // deposits are excluded from both sides and reported as income
            // under totals.placement_interest_earned.
            'fixed_deposit_basis' => 'equity_only',
            'periods' => $this->periods($portfolio, $transactions, $trades, $summary, $basis),
            'totals' => $this->totals($portfolio, $transactions, $trades, $summary),
            'closed_trades' => array_map(
                static fn (ClosedTrade $t): array => $t->toArray(),
                // Newest first, and capped: the full list can run to thousands
                // and the endpoint is a summary, not an export.
                array_slice(array_reverse($trades), 0, 250),
            ),
            'by_stock' => $this->byStock($portfolio, $transactions, $trades),
            'fiscal_year_note' => $basis === self::BASIS_FISCAL
                ? 'Fiscal years run Shrawan 1 to Ashad end. Boundaries come from a BS conversion '
                    .'table; override nepse.fiscal_year_starts if a year is wrong.'
                : null,
        ];
    }

    // ------------------------------------------------------------------
    // Closed-trade reconstruction
    // ------------------------------------------------------------------

    /**
     * Replay the ledger and pair sells back to the buys that funded them.
     *
     * @param  Collection<int, PortfolioTransaction>  $transactions
     * @return list<ClosedTrade> Oldest close first.
     */
    public function closedTrades(Collection $transactions): array
    {
        /** @var array<int, array{lots: list<array{qty: float, date: Carbon}>, qty: float, average: float, symbol: string}> $state */
        $state = [];
        $trades = [];

        foreach ($transactions as $transaction) {
            $stockId = $transaction->stock_id;
            $symbol = $transaction->stock?->symbol ?? (string) $stockId;

            $state[$stockId] ??= ['lots' => [], 'qty' => 0.0, 'average' => 0.0, 'symbol' => $symbol];
            $position = &$state[$stockId];

            $quantity = (float) $transaction->quantity;
            $price = (float) $transaction->price;
            $fees = $transaction->fees();

            switch ($transaction->type) {
                case TransactionType::Buy:
                case TransactionType::Rights:
                    $newQuantity = $position['qty'] + $quantity;
                    $totalCost = ($position['qty'] * $position['average']) + ($quantity * $price) + $fees;
                    $position['average'] = $newQuantity > 0.0 ? $totalCost / $newQuantity : 0.0;
                    $position['qty'] = $newQuantity;
                    $position['lots'][] = ['qty' => $quantity, 'date' => $transaction->transacted_on->copy()];
                    break;

                case TransactionType::Bonus:
                    // Bonus units inherit the position's age, not today's date —
                    // treating them as a fresh purchase would understate how
                    // long the holding has really been held.
                    $newQuantity = $position['qty'] + $quantity;
                    $position['average'] = $newQuantity > 0.0
                        ? ($position['qty'] * $position['average']) / $newQuantity
                        : 0.0;
                    $position['qty'] = $newQuantity;
                    $position['lots'][] = [
                        'qty' => $quantity,
                        'date' => ($position['lots'][0]['date'] ?? $transaction->transacted_on)->copy(),
                    ];
                    break;

                case TransactionType::Sell:
                    $sellable = min($quantity, $position['qty']);

                    if ($sellable <= 0.0) {
                        break;
                    }

                    $costBasis = $sellable * $position['average'];
                    $proceeds = ($sellable * $price) - $fees;
                    $matched = $this->consumeLots($position['lots'], $sellable);

                    $trades[] = new ClosedTrade(
                        symbol: $position['symbol'],
                        openedOn: $matched ?? $transaction->transacted_on->copy(),
                        closedOn: $transaction->transacted_on->copy(),
                        quantity: round($sellable, 2),
                        costBasis: round($costBasis, 2),
                        proceeds: round($proceeds, 2),
                        fees: round($fees, 2),
                        gain: round($proceeds - $costBasis, 2),
                        // No purchase lot matched: openedOn is the sell date
                        // standing in for a date the ledger never recorded.
                        // Averages tolerate that; a tax band must not.
                        acquisitionDateKnown: $matched !== null,
                    );

                    $position['qty'] = round($position['qty'] - $sellable, 2);
                    break;

                case TransactionType::Dividend:
                    // Income, not a round trip — counted in the period totals
                    // but never as a trade, so the win rate stays a win rate.
                    break;
            }

            unset($position);
        }

        return $trades;
    }

    /**
     * Take units off the front of the lot queue and return the weighted
     * acquisition date of what was consumed.
     *
     * Null when nothing matched — sold units with no recorded purchase, which
     * is an imported position whose history predates the ledger. The caller
     * substitutes the sell date and flags the trade, rather than this method
     * handing back a date it made up as though it were matched.
     *
     * A *partially* matched sell returns the matched portion's weighted date:
     * that is a real acquisition date for the units the ledger does know
     * about, and it is the closest honest answer available for the rest.
     *
     * @param  list<array{qty: float, date: Carbon}>  $lots
     */
    private function consumeLots(array &$lots, float $quantity): ?Carbon
    {
        $remaining = $quantity;
        $weightedDays = 0.0;
        $consumed = 0.0;
        $epoch = Carbon::create(2000, 1, 1);

        while ($remaining > 0.001 && $lots !== []) {
            $lot = &$lots[0];
            $take = min($lot['qty'], $remaining);

            $weightedDays += $take * $epoch->diffInDays($lot['date']);
            $consumed += $take;
            $lot['qty'] -= $take;
            $remaining -= $take;

            if ($lot['qty'] <= 0.001) {
                unset($lot);
                array_shift($lots);
            } else {
                unset($lot);
            }
        }

        if ($consumed <= 0.0) {
            return null;
        }

        return $epoch->copy()->addDays((int) round($weightedDays / $consumed));
    }

    // ------------------------------------------------------------------
    // Period buckets
    // ------------------------------------------------------------------

    /**
     * @param  Collection<int, PortfolioTransaction>  $transactions
     * @param  list<ClosedTrade>  $trades
     * @param  array<string, mixed>  $summary
     * @return list<array<string, mixed>>
     */
    private function periods(
        Portfolio $portfolio,
        Collection $transactions,
        array $trades,
        array $summary,
        string $basis,
    ): array {
        if ($transactions->isEmpty()) {
            return [];
        }

        $buckets = [];

        foreach ($transactions as $transaction) {
            $key = self::bucketKey($transaction->transacted_on, $basis);
            $buckets[$key['key']] ??= $this->emptyBucket($key);

            $bucket = &$buckets[$key['key']];
            $quantity = (float) $transaction->quantity;
            $price = (float) $transaction->price;
            $fees = $transaction->fees();
            $bucket['fees'] += $fees;

            switch ($transaction->type) {
                case TransactionType::Buy:
                case TransactionType::Rights:
                    $bucket['invested'] += ($quantity * $price) + $fees;
                    $bucket['buy_count']++;
                    break;

                case TransactionType::Sell:
                    $bucket['proceeds'] += ($quantity * $price) - $fees;
                    $bucket['sell_count']++;
                    break;

                case TransactionType::Dividend:
                    $bucket['dividends'] += abs($price) < 1e-9
                        ? $quantity - $fees
                        : ($quantity * $price) - $fees;
                    break;

                case TransactionType::Bonus:
                    $bucket['bonus_units'] += $quantity;
                    break;
            }

            unset($bucket);
        }

        foreach ($trades as $trade) {
            $key = self::bucketKey($trade->closedOn, $basis);
            $buckets[$key['key']] ??= $this->emptyBucket($key);

            $bucket = &$buckets[$key['key']];
            $bucket['realized_gain'] += $trade->gain;
            $bucket['trades_closed']++;
            $bucket['days_held_total'] += $trade->daysHeld();
            $trade->isWin() ? $bucket['wins']++ : $bucket['losses']++;

            if ($bucket['best'] === null || $trade->gain > $bucket['best']['gain']) {
                $bucket['best'] = ['symbol' => $trade->symbol, 'gain' => $trade->gain, 'gain_percent' => $trade->gainPercent()];
            }

            if ($bucket['worst'] === null || $trade->gain < $bucket['worst']['gain']) {
                $bucket['worst'] = ['symbol' => $trade->symbol, 'gain' => $trade->gain, 'gain_percent' => $trade->gainPercent()];
            }

            unset($bucket);
        }

        ksort($buckets);
        $current = self::bucketKey(Carbon::today(), $basis)['key'];

        // Mark the portfolio at every period boundary in one pass. This is what
        // makes a per-year return possible at all: a year's return needs the
        // capital it started with, and that is only knowable by replaying the
        // ledger to the boundary and pricing it at that date's closes.
        $boundaries = [];

        foreach ($buckets as $bucket) {
            $boundaries[] = Carbon::parse($bucket['starts_on'])->subDay();
            $boundaries[] = $this->periodEnd($bucket['ends_on']);
        }

        $marks = $this->timeSeries->valuesOn($portfolio, $boundaries);
        $periods = [];

        foreach ($buckets as $key => $bucket) {
            // PHP silently casts numeric-string array keys to int, so this must
            // compare as strings or the live period never matches.
            $isCurrent = (string) $key === $current;
            $closed = $bucket['trades_closed'];

            $start = Carbon::parse($bucket['starts_on']);
            $end = $this->periodEnd($bucket['ends_on']);
            $opening = $marks[$start->copy()->subDay()->toDateString()] ?? null;
            $closing = $marks[$end->toDateString()] ?? null;

            $performance = $this->periodReturn(
                $transactions, $start, $end, $opening, $closing, (string) $key, $basis,
            );

            $periods[] = [
                'key' => (string) $key,
                'label' => $bucket['label'],
                'starts_on' => $bucket['starts_on'],
                'ends_on' => $bucket['ends_on'],
                'is_current' => $isCurrent,
                'opening_value' => $opening === null ? null : $opening['value'],
                'closing_value' => $closing === null ? null : $closing['value'],
                'values_complete' => ($opening['complete'] ?? false) && ($closing['complete'] ?? false),
                'unpriced_symbols' => array_values(array_unique(array_merge(
                    $opening['unpriced_symbols'] ?? [],
                    $closing['unpriced_symbols'] ?? [],
                ))),
                // Priced is not the same as priced from history. A boundary
                // marked partly on `stocks.last_price` — a scrip with no row in
                // stock_prices or fund_navs — is provisional, and a period
                // return built on one has to say so rather than read as
                // measured. See MarkSourceReconciler.
                'values_provisional' => ($opening['provisional'] ?? false) || ($closing['provisional'] ?? false),
                'spot_marked_symbols' => array_values(array_unique(array_merge(
                    $opening['spot_marked_symbols'] ?? [],
                    $closing['spot_marked_symbols'] ?? [],
                ))),
                // The headline answer: annualised money-weighted return for the
                // period, and how the same cash would have done in a deposit.
                'return_percent' => $performance['return_percent'],
                'simple_return_percent' => $performance['simple_return_percent'],
                'profit' => $performance['profit'],
                'net_contribution' => $performance['net_contribution'],
                'days_in_period' => $performance['days'],
                'is_partial' => $performance['is_partial'],
                'fixed_deposit_rate' => $performance['fd_rate'],
                'fixed_deposit_value' => $performance['fd_value'],
                'value_vs_fixed_deposit' => $performance['value_vs_fd'],
                'beat_fixed_deposit' => $performance['beat_fd'],
                'invested' => round($bucket['invested'], 2),
                'proceeds' => round($bucket['proceeds'], 2),
                'dividends' => round($bucket['dividends'], 2),
                'fees' => round($bucket['fees'], 2),
                'bonus_units' => round($bucket['bonus_units'], 2),
                'realized_gain' => round($bucket['realized_gain'], 2),
                // Dividends are realised income too, so the number a tax form
                // or a "what did this year earn me" question wants is both.
                'net_realized' => round($bucket['realized_gain'] + $bucket['dividends'], 2),
                // Only the live period can carry a mark-to-market number.
                'unrealized_gain' => $isCurrent ? $summary['unrealized_gain'] : null,
                'total_gain' => $isCurrent
                    ? round($bucket['realized_gain'] + $bucket['dividends'] + (float) $summary['unrealized_gain'], 2)
                    : round($bucket['realized_gain'] + $bucket['dividends'], 2),
                'buy_count' => $bucket['buy_count'],
                'sell_count' => $bucket['sell_count'],
                'trades_closed' => $closed,
                'wins' => $bucket['wins'],
                'losses' => $bucket['losses'],
                'win_rate' => $closed > 0 ? round($bucket['wins'] / $closed * 100, 1) : null,
                'average_days_held' => $closed > 0 ? (int) round($bucket['days_held_total'] / $closed) : null,
                'best_trade' => $bucket['best'],
                'worst_trade' => $bucket['worst'],
                'approximate_boundary' => $bucket['approximate'],
            ];
        }

        return array_reverse($periods);
    }

    /**
     * A period never ends in the future — the live year is marked at today.
     */
    private function periodEnd(string $endsOn): Carbon
    {
        $end = Carbon::parse($endsOn)->startOfDay();
        $today = Carbon::today();

        return $end->greaterThan($today) ? $today : $end;
    }

    /**
     * One period's return, and what a fixed deposit would have done with the
     * same money over the same dates.
     *
     * The return is money-weighted (XIRR) rather than (close − open) ÷ open,
     * because money arrives mid-year: Rs 5 lakh added in Ashad cannot be
     * credited with a whole year's performance. The period is modelled as
     * buying the opening portfolio on day one, transacting through the year, and
     * selling everything at the close — which is exactly the flow series XIRR
     * takes.
     *
     * The rupee comparison against the deposit is the number that actually
     * answers "should I have just left it in the bank", and unlike an annualised
     * percentage it stays honest over a part-year.
     *
     * @param  Collection<int, PortfolioTransaction>  $transactions
     * @param  array{value: float, complete: bool}|null  $opening
     * @param  array{value: float, complete: bool}|null  $closing
     * @return array{return_percent: ?float, simple_return_percent: ?float, profit: ?float, net_contribution: float, days: int, is_partial: bool, fd_rate: float, fd_value: ?float, value_vs_fd: ?float, beat_fd: ?bool}
     */
    private function periodReturn(
        Collection $transactions,
        Carbon $start,
        Carbon $end,
        ?array $opening,
        ?array $closing,
        string $key,
        string $basis,
    ): array {
        $fdRate = $this->fixedDepositRate($key);
        $days = (int) $start->diffInDays($end) + 1;
        $isPartial = $end->lessThan(Carbon::parse($this->periodBoundEnd($start, $basis)));

        // A boundary the price history cannot reach is unknown, not zero.
        $openingValue = ($opening['known'] ?? false) ? $opening['value'] : null;
        $closingValue = ($closing['known'] ?? false) ? $closing['value'] : null;

        // Cash moved during the period, from the investor's point of view:
        // buying costs you money, selling and dividends give it back.
        $flows = [];
        $contribution = 0.0;

        foreach ($transactions as $transaction) {
            $date = $transaction->transacted_on;

            if ($date->lessThan($start) || $date->greaterThan($end)) {
                continue;
            }

            $amount = self::cashFlow($transaction);

            if (abs($amount) < 1e-9) {
                continue;
            }

            $flows[] = ['date' => $date->copy(), 'amount' => $amount];
            $contribution += -$amount;
        }

        $contribution = round($contribution, 2);

        if ($openingValue === null || $closingValue === null) {
            return [
                'return_percent' => null,
                'simple_return_percent' => null,
                'profit' => null,
                'net_contribution' => $contribution,
                'days' => $days,
                'is_partial' => $isPartial,
                'fd_rate' => $fdRate,
                'fd_value' => null,
                'value_vs_fd' => null,
                'beat_fd' => null,
            ];
        }

        // Opening capital behaves as a purchase on day one, the closing value as
        // a sale on the last day.
        $series = collect([
            ...($openingValue > 0.0 ? [['date' => $start->copy(), 'amount' => -$openingValue]] : []),
            ...$flows,
            ...($closingValue > 0.0 ? [['date' => $end->copy(), 'amount' => $closingValue]] : []),
        ]);

        $return = $this->rateOfReturn($series->all());
        $fdValue = $this->fixedDepositValue($openingValue, $flows, $start, $end, $fdRate);

        // What the period actually earned, in rupees: everything the portfolio
        // gained beyond the money put into it. Realised and unrealised together,
        // which is the honest answer to "what did this year make me".
        $profit = round($closingValue - $openingValue - $contribution, 2);
        $capitalBase = $openingValue + max(0.0, $contribution);

        // An annualised rate over a part-year extrapolates wildly — six weeks of
        // -6% reads as -48% a year. The simple return is not annualised, so it
        // stays the honest headline until the period completes.
        $simpleReturn = $capitalBase > 0.0 ? round($profit / $capitalBase * 100, 2) : null;

        // A period with no capital at either end has no return to report, and a
        // zero would read as a flat year rather than an absent one.
        $hadCapital = $openingValue > 0.0 || $contribution > 0.0;

        return [
            'return_percent' => $hadCapital ? $return : null,
            'simple_return_percent' => $hadCapital ? $simpleReturn : null,
            'profit' => $hadCapital ? $profit : null,
            'net_contribution' => $contribution,
            'days' => $days,
            'is_partial' => $isPartial,
            'fd_rate' => $fdRate,
            'fd_value' => $hadCapital ? round($fdValue, 2) : null,
            'value_vs_fd' => $hadCapital ? round($closingValue - $fdValue, 2) : null,
            'beat_fd' => $hadCapital ? $closingValue > $fdValue : null,
        ];
    }

    /**
     * What the same deposits and withdrawals would be worth at $end in a fixed
     * deposit compounding at $rate percent a year.
     *
     * Money you put in earns until the end date; money you took out (a sale, a
     * dividend) stops earning when you took it, which is what makes this a fair
     * like-for-like against the equity result rather than a strawman.
     *
     * @param  list<array{date: Carbon, amount: float}>  $flows  Investor-perspective: buys negative.
     */
    private function fixedDepositValue(
        float $openingValue,
        array $flows,
        Carbon $start,
        Carbon $end,
        float $rate,
    ): float {
        $r = $rate / 100;
        $grow = static fn (float $amount, Carbon $from): float => $amount * (1 + $r) ** ($from->diffInDays($end) / 365);

        $value = $openingValue > 0.0 ? $grow($openingValue, $start) : 0.0;

        foreach ($flows as $flow) {
            // A buy (negative flow) is a deposit; a sale is a withdrawal.
            $value += $grow(-$flow['amount'], $flow['date']);
        }

        return $value;
    }

    /**
     * The deposit rate for a period, falling back to the flat configured rate.
     */
    private function fixedDepositRate(?string $key): float
    {
        // An explicit override applies to every period: the caller is asking
        // "what if the rate were this", not "what if it were this in one year".
        if ($this->rateOverride !== null) {
            return $this->rateOverride;
        }

        $rates = (array) config('nepse.benchmark.fixed_deposit_rates', []);
        $flat = (float) config('nepse.benchmark.fixed_deposit_rate', 8.0);

        if ($key !== null && isset($rates[$key])) {
            return (float) $rates[$key];
        }

        return $flat;
    }

    /**
     * The natural end of the period $start belongs to, ignoring today — used
     * only to decide whether a period is still running.
     */
    private function periodBoundEnd(Carbon $start, string $basis): string
    {
        return $basis === self::BASIS_CALENDAR
            ? $start->copy()->endOfYear()->toDateString()
            : NepaliFiscalYear::containing($start)->end->toDateString();
    }

    /**
     * Cash impact of one transaction, from the investor's pocket.
     *
     * Public and static — {@see IndexBenchmarkService}
     * reuses this exact convention (buy negative, sell/dividend positive) so
     * its "bought the index instead" comparison is built from the same cash
     * flows as this class's own fixed-deposit benchmark, never a re-derived
     * approximation of them.
     */
    public static function cashFlow(PortfolioTransaction $transaction): float
    {
        $quantity = (float) $transaction->quantity;
        $price = (float) $transaction->price;
        $fees = $transaction->fees();

        return match ($transaction->type) {
            TransactionType::Buy, TransactionType::Rights => -(($quantity * $price) + $fees),
            TransactionType::Sell => ($quantity * $price) - $fees,
            TransactionType::Dividend => abs($price) < 1e-9 ? $quantity - $fees : ($quantity * $price) - $fees,
            TransactionType::Bonus => 0.0,
        };
    }

    /**
     * Which reporting period a date falls in, on either basis.
     *
     * Public static for the same reason {@see cashFlow()} is: more than one
     * subsystem now buckets by Nepali fiscal year — the capital-gains
     * estimator among them, since that is how gains are actually reported —
     * and a second implementation of the boundary rule would be a second set
     * of year edges to drift apart.
     *
     * @return array{key: string, label: string, starts_on: string, ends_on: string, approximate: bool}
     */
    public static function bucketKey(Carbon $date, string $basis): array
    {
        if ($basis === self::BASIS_CALENDAR) {
            return [
                'key' => $date->format('Y'),
                'label' => $date->format('Y'),
                'starts_on' => $date->copy()->startOfYear()->toDateString(),
                'ends_on' => $date->copy()->endOfYear()->toDateString(),
                'approximate' => false,
            ];
        }

        $fy = NepaliFiscalYear::containing($date);

        return [
            'key' => $fy->key(),
            'label' => 'FY '.$fy->label(),
            'starts_on' => $fy->start->toDateString(),
            'ends_on' => $fy->end->toDateString(),
            'approximate' => $fy->approximate,
        ];
    }

    /**
     * @param  array{key: string, label: string, starts_on: string, ends_on: string, approximate: bool}  $key
     * @return array<string, mixed>
     */
    private function emptyBucket(array $key): array
    {
        return [
            'label' => $key['label'],
            'starts_on' => $key['starts_on'],
            'ends_on' => $key['ends_on'],
            'approximate' => $key['approximate'],
            'invested' => 0.0,
            'proceeds' => 0.0,
            'dividends' => 0.0,
            'fees' => 0.0,
            'bonus_units' => 0.0,
            'realized_gain' => 0.0,
            'buy_count' => 0,
            'sell_count' => 0,
            'trades_closed' => 0,
            'wins' => 0,
            'losses' => 0,
            'days_held_total' => 0,
            'best' => null,
            'worst' => null,
        ];
    }

    // ------------------------------------------------------------------
    // Lifetime totals
    // ------------------------------------------------------------------

    /**
     * @param  Collection<int, PortfolioTransaction>  $transactions
     * @param  list<ClosedTrade>  $trades
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function totals(Portfolio $portfolio, Collection $transactions, array $trades, array $summary): array
    {
        $realized = array_sum(array_map(static fn (ClosedTrade $t): float => $t->gain, $trades));
        $dividends = 0.0;
        $fees = 0.0;

        foreach ($transactions as $transaction) {
            $fees += $transaction->fees();

            if ($transaction->type === TransactionType::Dividend) {
                $dividends += abs((float) $transaction->price) < 1e-9
                    ? (float) $transaction->quantity - $transaction->fees()
                    : ((float) $transaction->quantity * (float) $transaction->price) - $transaction->fees();
            }
        }

        $wins = count(array_filter($trades, static fn (ClosedTrade $t): bool => $t->isWin()));
        $closed = count($trades);
        $unrealized = (float) $summary['unrealized_gain'];
        $first = $transactions->first()?->transacted_on;

        $winGains = array_map(
            static fn (ClosedTrade $t): float => $t->gain,
            array_filter($trades, static fn (ClosedTrade $t): bool => $t->isWin()),
        );
        $lossGains = array_map(
            static fn (ClosedTrade $t): float => $t->gain,
            array_filter($trades, static fn (ClosedTrade $t): bool => ! $t->isWin()),
        );

        return [
            'realized_gain' => round($realized, 2),
            'dividends' => round($dividends, 2),
            'unrealized_gain' => round($unrealized, 2),
            'net_profit' => round($realized + $dividends + $unrealized, 2),
            'total_fees' => round($fees, 2),
            'invested_now' => $summary['total_invested'],
            'market_value' => $summary['total_value'],
            'trades_closed' => $closed,
            'wins' => $wins,
            'losses' => $closed - $wins,
            'win_rate' => $closed > 0 ? round($wins / $closed * 100, 1) : null,
            'average_win' => $winGains === [] ? null : round(array_sum($winGains) / count($winGains), 2),
            'average_loss' => $lossGains === [] ? null : round(array_sum($lossGains) / count($lossGains), 2),
            // Total won divided by total lost. Above 1 means the winners are
            // carrying the account even if the win rate is under half.
            'profit_factor' => $lossGains === [] || array_sum($lossGains) === 0.0
                ? null
                : round(array_sum($winGains) / abs(array_sum($lossGains)), 2),
            'average_days_held' => $closed > 0
                ? (int) round(array_sum(array_map(static fn (ClosedTrade $t): int => $t->daysHeld(), $trades)) / $closed)
                : null,
            'first_transaction_on' => $first?->toDateString(),
            'years_active' => $first === null ? null : round($first->diffInDays(Carbon::today()) / 365.25, 2),
            'annualized_return_percent' => $this->xirr($transactions, (float) $summary['total_value']),
        ] + $this->fixedDepositComparison($transactions, (float) $summary['total_value'], $summary)
            + $this->contributedCapitalComparison($portfolio, $summary);
    }

    /**
     * Money-weighted annual return (XIRR).
     *
     * A simple "total gain / total invested" is misleading the moment you add
     * money over time — Rs 1 lakh put in last month and Rs 1 lakh put in three
     * years ago are not comparable. XIRR solves for the rate that makes every
     * dated cash flow, plus today's market value, net to zero.
     *
     * @param  Collection<int, PortfolioTransaction>  $transactions
     */
    public function xirr(Collection $transactions, float $currentValue): ?float
    {
        /** @var list<array{date: Carbon, amount: float}> $flows */
        $flows = [];

        foreach ($transactions as $transaction) {
            $quantity = (float) $transaction->quantity;
            $price = (float) $transaction->price;
            $fees = $transaction->fees();

            $amount = match ($transaction->type) {
                // Money leaving your pocket is negative.
                TransactionType::Buy, TransactionType::Rights => -(($quantity * $price) + $fees),
                TransactionType::Sell => ($quantity * $price) - $fees,
                TransactionType::Dividend => abs($price) < 1e-9 ? $quantity - $fees : ($quantity * $price) - $fees,
                // Bonus units cost nothing, so they move no cash.
                TransactionType::Bonus => 0.0,
            };

            if (abs($amount) > 1e-9) {
                $flows[] = ['date' => $transaction->transacted_on->copy(), 'amount' => $amount];
            }
        }

        if ($currentValue > 0.0) {
            $flows[] = ['date' => Carbon::today(), 'amount' => $currentValue];
        }

        return $this->rateOfReturn($flows);
    }

    /**
     * Lifetime comparison against leaving the money in a fixed deposit.
     *
     * The question a stock portfolio has to answer is not "did it go up" but
     * "did it beat the boring alternative". So the same deposits and
     * withdrawals, on the same dates, are compounded at the deposit rate and
     * the two end values are put side by side.
     *
     * **The comparison is equity against a hypothetical deposit, and nothing
     * else.** Both sides are built only from `portfolio_transactions` — buys,
     * sells, dividends — so money actually sitting in a real fixed deposit
     * appears on neither side. That is deliberate rather than an omission:
     * real deposit money is *already* earning the deposit rate, so putting it
     * on both sides of a "did equities beat a deposit" question guarantees a
     * tie on that slice and dilutes the verdict in exact proportion to how
     * much of the book is in FDs. Hold half your capital in deposits and the
     * equity verdict would be halved, which tells you nothing about
     * stock-picking.
     *
     * Real FD income is therefore reported *beside* the comparison, as actual
     * income, never folded into it — the same separation the market benchmark
     * keeps between the portfolio's return and the index's.
     *
     * @param  Collection<int, PortfolioTransaction>  $transactions
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function fixedDepositComparison(Collection $transactions, float $currentValue, array $summary = []): array
    {
        $rate = $this->fixedDepositRate(null);
        $first = $transactions->first()?->transacted_on;
        $placements = $this->placementIncome($summary);

        if ($first === null) {
            return [
                'fixed_deposit_rate' => $rate,
                'fixed_deposit_value' => null,
                'value_vs_fixed_deposit' => null,
                'beat_fixed_deposit' => null,
            ] + $placements;
        }

        $end = Carbon::today();
        $flows = [];

        foreach ($transactions as $transaction) {
            $amount = self::cashFlow($transaction);

            if (abs($amount) > 1e-9) {
                $flows[] = ['date' => $transaction->transacted_on->copy(), 'amount' => $amount];
            }
        }

        $fdValue = $this->fixedDepositValue(0.0, $flows, $first, $end, $rate);

        return [
            'fixed_deposit_rate' => $rate,
            'fixed_deposit_value' => round($fdValue, 2),
            'value_vs_fixed_deposit' => round($currentValue - $fdValue, 2),
            'beat_fixed_deposit' => $currentValue > $fdValue,
        ] + $placements;
    }

    /**
     * The second basis: money you actually put in, against a deposit.
     *
     * {@see fixedDepositComparison()} answers "did these *trades* beat a
     * deposit", building both sides from `portfolio_transactions`. That is a
     * question about stock-picking, and it deliberately ignores money sitting
     * idle in the account.
     *
     * This answers the other question, the one most people mean: **I put this
     * much money in on these dates — would a fixed deposit have done better?**
     * So the flows are the real contributions, read from the cash ledger, and
     * the terminal value is everything the portfolio is now worth — shares,
     * idle cash and settled deposits alike — because the contributions bought
     * all three.
     *
     * Three row types count, and the third is the one that is easy to miss:
     *
     *   deposit              money into the broker account
     *   withdrawal           money back out of it
     *   subscription_funding IPO and rights money, paid straight from your bank
     *
     * An IPO allotment never passes through the broker, so a comparison built
     * only from broker deposits would leave that capital out of the
     * denominator entirely and flatter the return by exactly its size.
     *
     * The funding leg is counted, never its paired `subscription_debit`. The
     * debit is cash turning into shares — an internal move, exactly like a buy
     * settlement — and counting both legs would double the IPO capital.
     *
     * Returns nulls, not zeroes, when there are no recorded contributions: a
     * portfolio whose cash ledger was never imported has not contributed
     * nothing, it has simply not said.
     *
     * @return array<string, mixed>
     */
    private function contributedCapitalComparison(Portfolio $portfolio, array $summary): array
    {
        $rate = $this->fixedDepositRate(null);

        $rows = DB::table('cash_transactions')
            ->where('portfolio_id', $portfolio->id)
            ->whereNull('voided_at')
            ->whereIn('type', ['deposit', 'withdrawal', IpoCapitalService::FUNDING_TYPE])
            ->orderBy('transacted_on')
            ->get(['type', 'amount', 'transacted_on']);

        if ($rows->isEmpty()) {
            return [
                'capital_basis_available' => false,
                'capital_contributed' => null,
                'capital_withdrawn' => null,
                'net_capital' => null,
                'capital_fixed_deposit_value' => null,
                'net_worth_vs_capital_fixed_deposit' => null,
                'beat_fixed_deposit_on_capital' => null,
                'capital_basis_complete' => false,
                'capital_unexplained_cost' => null,
                'capital_unexplained_note' => null,
                'capital_basis_note' => 'No deposits or withdrawals are recorded, so there is no '
                    .'contributed capital to compare. Upload your broker statement to fill this in.',
            ];
        }

        $flows = [];
        $contributed = 0.0;
        $withdrawn = 0.0;

        foreach ($rows as $row) {
            $magnitude = abs((float) $row->amount);

            if ($magnitude < 1e-9) {
                continue;
            }

            $date = Carbon::parse((string) $row->transacted_on);

            if ($row->type === 'withdrawal') {
                $withdrawn += $magnitude;
                // Money leaving your pocket's investment is a positive flow
                // back to you, matching cashFlow()'s convention for a sell.
                $flows[] = ['date' => $date, 'amount' => $magnitude];

                continue;
            }

            $contributed += $magnitude;
            $flows[] = ['date' => $date, 'amount' => -$magnitude];
        }

        if ($flows === []) {
            return [
                'capital_basis_available' => false,
                'capital_contributed' => null,
                'capital_withdrawn' => null,
                'net_capital' => null,
                'capital_fixed_deposit_value' => null,
                'net_worth_vs_capital_fixed_deposit' => null,
                'beat_fixed_deposit_on_capital' => null,
                'capital_basis_complete' => false,
                'capital_unexplained_cost' => null,
                'capital_unexplained_note' => null,
                'capital_basis_note' => 'Every recorded contribution is zero-valued, so there is '
                    .'nothing to compound.',
            ];
        }

        $start = $flows[0]['date']->copy();
        $fdValue = $this->fixedDepositValue(0.0, $flows, $start, Carbon::today(), $rate);
        $unexplained = $this->costWithoutCash($portfolio);

        // Everything the contributions paid for: shares at market, cash left
        // over, and any money moved into a real deposit.
        $netWorth = (float) ($summary['net_worth'] ?? 0.0);

        return [
            'capital_basis_available' => true,
            'capital_contributed' => round($contributed, 2),
            'capital_withdrawn' => round($withdrawn, 2),
            'net_capital' => round($contributed - $withdrawn, 2),
            'capital_fixed_deposit_value' => round($fdValue, 2),
            'net_worth_vs_capital_fixed_deposit' => round($netWorth - $fdValue, 2),
            'beat_fixed_deposit_on_capital' => $netWorth > $fdValue,
            /*
             * Coverage, the same discipline the index benchmark keeps. A
             * capital figure missing a sixth of the money is not a small error
             * — it is the denominator — so the shortfall is named rather than
             * folded in or quietly ignored.
             */
            'capital_basis_complete' => $unexplained < 0.01,
            'capital_unexplained_cost' => $unexplained < 0.01 ? null : $unexplained,
            'capital_unexplained_note' => $unexplained < 0.01
                ? null
                : sprintf(
                    'Understated by up to NPR %s: that much cost belongs to holdings bought with no '
                    .'matching cash movement in the ledger. Mutual fund units are the usual cause '
                    .'— no broker statement covers them, so the money that bought them has to be '
                    .'entered by hand. Because this figure is the denominator, the return above is '
                    .'flattered until it is.',
                    number_format($unexplained, 2),
                ),
            'capital_basis_note' => sprintf(
                'Compares everything the portfolio is now worth (NPR %s) against the %s you actually '
                .'put in, compounded at %s%% from each contribution date. IPO and rights money paid '
                .'from your bank is included — it never reaches the broker statement, and leaving it '
                .'out would flatter the return by its full size.',
                number_format($netWorth, 2),
                number_format($contributed - $withdrawn, 2),
                $rate,
            ),
        ];
    }

    /**
     * Cost the cash ledger cannot account for.
     *
     * A parcel with a real cost and no matching cash movement anywhere. The
     * shares are counted; the money that bought them is not. Contributed
     * capital is then understated by exactly that cost, and because it is the
     * denominator, the return is flattered.
     *
     * In a fictional example, 10,000.00 of unexplained mutual-fund cost is
     * 12.5% of 80,000.00 in contributions, too much to leave unsaid.
     *
     * **Mutual funds have no broker statement**, so this gap cannot be closed
     * by an import the way a second broker's equity trades could be. Whatever
     * fills it will be a deliberate entry by the holder; until that is
     * designed, the figure is reported as incomplete rather than guessed at,
     * and the wording must not promise an import that does not exist.
     *
     * The test is provenance, not price: a `secondary` parcel whose notes do
     * not come from a broker-statement import has no settlement behind it.
     * Channels funded outside the broker are excluded because their capital is
     * recorded deliberately, by `IpoCapitalService`.
     */
    private function costWithoutCash(Portfolio $portfolio): float
    {
        $rows = DB::table('portfolio_transactions')
            ->where('portfolio_id', $portfolio->id)
            ->where('type', '!=', TransactionType::Sell->value)
            ->where('acquisition_channel', AcquisitionChannel::Secondary->value)
            ->where(static function ($query): void {
                $query->whereNull('notes')->orWhere('notes', 'not like', 'Broker statement%');
            })
            ->get(['quantity', 'price']);

        return round((float) $rows->sum(
            static fn (object $r): float => (float) $r->quantity * (float) $r->price,
        ), 2);
    }

    /**
     * Real fixed-deposit income, reported alongside the hypothetical-deposit
     * comparison and explicitly outside it.
     *
     * Null rather than 0.0 when the portfolio holds no placements at all:
     * "no deposits" and "deposits that earned nothing" are different
     * statements, and only one of them is true here.
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function placementIncome(array $summary): array
    {
        /** @var array<string, mixed> $placements */
        $placements = is_array($summary['placements'] ?? null) ? $summary['placements'] : [];
        $count = (int) ($placements['count'] ?? 0);

        return [
            // What the two sides of value_vs_fixed_deposit actually are, so no
            // caller has to guess whether real FDs are inside the number.
            'fixed_deposit_comparison_basis' => 'equity_only',
            'fixed_deposit_comparison_note' => sprintf(
                'Compares the equity book (market value NPR %s) against a hypothetical deposit at %s%% fed the '
                .'same trade cash flows on the same dates. Money held in real fixed deposits is on neither '
                .'side — it already earns the deposit rate, so including it would guarantee a tie on that '
                .'slice. Real placement income is reported separately as placement_interest_earned.',
                number_format((float) ($summary['total_value'] ?? 0.0), 2),
                $this->fixedDepositRate(null),
            ),
            'placement_count' => $count,
            'placement_principal_placed' => $count > 0 ? ($placements['principal_placed'] ?? null) : null,
            'placement_interest_earned' => $count > 0 ? ($placements['total_interest'] ?? null) : null,
            'placement_value_in_net_worth' => $count > 0 ? ($placements['net_worth_contribution'] ?? null) : null,
            'placement_note' => $placements['note'] ?? null,
        ];
    }

    /**
     * Solve a dated cash-flow series for its annualised rate of return.
     *
     * The shared core of every return figure here: the lifetime XIRR and each
     * period's own return differ only in which flows they feed it.
     *
     * @param  list<array{date: Carbon, amount: float}>  $flows
     */
    public function rateOfReturn(array $flows): ?float
    {
        $flows = array_values(array_filter(
            $flows,
            static fn (array $flow): bool => abs($flow['amount']) > 1e-9,
        ));

        if (count($flows) < 2) {
            return null;
        }

        // A root can only be bracketed with at least one flow of each sign.
        $hasNegative = false;
        $hasPositive = false;

        foreach ($flows as $flow) {
            $flow['amount'] < 0.0 ? $hasNegative = true : $hasPositive = true;
        }

        if (! $hasNegative || ! $hasPositive) {
            return null;
        }

        usort($flows, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);
        $start = $flows[0]['date'];

        // Bisection rather than Newton-Raphson: slower, but it cannot diverge on
        // the irregular flow patterns a real portfolio produces, and the range
        // below covers -99% to +1000% a year.
        $low = -0.9999;
        $high = 10.0;
        $npvLow = $this->npv($flows, $start, $low);
        $npvHigh = $this->npv($flows, $start, $high);

        if ($npvLow * $npvHigh > 0.0) {
            return null;
        }

        for ($i = 0; $i < 128; $i++) {
            $mid = ($low + $high) / 2;
            $npv = $this->npv($flows, $start, $mid);

            if (abs($npv) < 0.01 || ($high - $low) < 1e-7) {
                return round($mid * 100, 2);
            }

            if ($npv * $npvLow < 0.0) {
                $high = $mid;
                $npvHigh = $npv;
            } else {
                $low = $mid;
                $npvLow = $npv;
            }
        }

        return round((($low + $high) / 2) * 100, 2);
    }

    /**
     * @param  list<array{date: Carbon, amount: float}>  $flows
     */
    private function npv(array $flows, Carbon $start, float $rate): float
    {
        $total = 0.0;

        foreach ($flows as $flow) {
            $years = $start->diffInDays($flow['date']) / 365.0;
            $total += $flow['amount'] / (1 + $rate) ** $years;
        }

        return $total;
    }

    // ------------------------------------------------------------------
    // Per-scrip lifetime view
    // ------------------------------------------------------------------

    /**
     * @param  Collection<int, PortfolioTransaction>  $transactions
     * @param  list<ClosedTrade>  $trades
     * @return list<array<string, mixed>>
     */
    private function byStock(Portfolio $portfolio, Collection $transactions, array $trades): array
    {
        $rows = [];

        foreach ($trades as $trade) {
            $rows[$trade->symbol] ??= $this->emptyStockRow($trade->symbol);
            $rows[$trade->symbol]['realized_gain'] += $trade->gain;
            $rows[$trade->symbol]['trades_closed']++;
            $trade->isWin() ? $rows[$trade->symbol]['wins']++ : $rows[$trade->symbol]['losses']++;
        }

        foreach ($transactions as $transaction) {
            $symbol = $transaction->stock?->symbol;

            if ($symbol === null) {
                continue;
            }

            $rows[$symbol] ??= $this->emptyStockRow($symbol);
            $rows[$symbol]['fees'] += $transaction->fees();

            if ($transaction->type === TransactionType::Dividend) {
                $rows[$symbol]['dividends'] += abs((float) $transaction->price) < 1e-9
                    ? (float) $transaction->quantity - $transaction->fees()
                    : ((float) $transaction->quantity * (float) $transaction->price) - $transaction->fees();
            }
        }

        // last_price is required: currentValue() reads it, and a narrower select
        // silently yields null unrealised gains for every position.
        foreach ($portfolio->openHoldings()->with('stock:id,symbol,last_price')->get() as $holding) {
            $symbol = $holding->stock?->symbol;

            if ($symbol === null) {
                continue;
            }

            $rows[$symbol] ??= $this->emptyStockRow($symbol);
            $rows[$symbol]['quantity'] = (float) $holding->quantity;
            $rows[$symbol]['average_cost'] = round((float) $holding->average_cost, 2);
            $rows[$symbol]['market_value'] = $holding->currentValue();
            $rows[$symbol]['unrealized_gain'] = $holding->unrealizedGain();
        }

        $out = [];

        foreach ($rows as $row) {
            $row['realized_gain'] = round($row['realized_gain'], 2);
            $row['dividends'] = round($row['dividends'], 2);
            $row['fees'] = round($row['fees'], 2);
            $row['total_gain'] = round(
                $row['realized_gain'] + $row['dividends'] + (float) ($row['unrealized_gain'] ?? 0.0),
                2,
            );
            $out[] = $row;
        }

        usort($out, static fn (array $a, array $b): int => $b['total_gain'] <=> $a['total_gain']);

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyStockRow(string $symbol): array
    {
        return [
            'symbol' => $symbol,
            'quantity' => 0.0,
            'average_cost' => null,
            'market_value' => null,
            'unrealized_gain' => null,
            'realized_gain' => 0.0,
            'dividends' => 0.0,
            'fees' => 0.0,
            'trades_closed' => 0,
            'wins' => 0,
            'losses' => 0,
        ];
    }
}
