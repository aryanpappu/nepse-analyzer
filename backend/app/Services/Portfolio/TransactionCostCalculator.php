<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use Illuminate\Support\Carbon;

/**
 * What a NEPSE trade really costs.
 *
 * Fees on a recorded transaction are whatever the user typed in, which is fine
 * for accounting a trade that already happened and useless for deciding one
 * that has not. Real NEPSE costs are computable to the paisa: a tiered broker
 * commission, a flat SEBON regulatory fee, and a flat per-scrip DP charge. A
 * round trip lands somewhere around 0.6–1.2% of turnover, and the DP charge
 * makes the small end of that range much worse — enough to invert a result
 * that looked marginally profitable gross.
 *
 * Deliberately standalone and dependency-light: no Eloquent, no clock, no
 * container, and no reliance on anything in this namespace beyond its own
 * value objects. It is a prerequisite for honest paper trading and for the
 * playbook backtest, both of which need to price a hypothetical trade that has
 * no transaction row anywhere.
 *
 * **It never rewrites a recorded cost.** Positions are a function of the
 * ledger and buys capitalise the *recorded* fees into the cost base, so
 * retro-computing fees would silently move every historical cost basis and
 * realised gain — and would do it by applying today's regulated rates to
 * trades made under older ones. A caller asks for a computed figure; nothing
 * here asks the ledger to change. {@see reconcile()} exists so the difference
 * between typed and computed can be *shown* without being applied.
 *
 * The whole schedule is config-driven — see {@see TransactionCostSchedule} for
 * the shape and for why `effectiveFrom` and `application` are part of it.
 */
final class TransactionCostCalculator
{
    public const SIDE_BUY = 'buy';

    public const SIDE_SELL = 'sell';

    /** @var list<TransactionCostSchedule> */
    private readonly array $schedules;

    private readonly string $defaultInstrumentClass;

    /**
     * @param  list<TransactionCostSchedule>|null  $schedules  Null or empty falls back to the
     *                                                         built-in NEPSE schedule, which stamps every breakdown
     *                                                         `built_in_default` so an assumed rate is never mistaken
     *                                                         for a configured one.
     */
    public function __construct(?array $schedules = null, ?string $defaultInstrumentClass = null)
    {
        $this->schedules = $schedules === null || $schedules === []
            ? TransactionCostSchedule::nepseDefaults()
            : array_values($schedules);

        $this->defaultInstrumentClass = $defaultInstrumentClass ?? TransactionCostSchedule::CLASS_EQUITY;
    }

    /**
     * Build from `nepse.transaction_costs`, falling back to the built-in
     * schedule while that key does not exist.
     */
    public static function fromConfig(): self
    {
        /** @var array<int, array<string, mixed>> $raw */
        $raw = (array) config('nepse.transaction_costs.schedules', []);

        return new self(
            $raw === [] ? null : TransactionCostSchedule::listFromArray($raw),
            (string) config(
                'nepse.transaction_costs.default_instrument_class',
                TransactionCostSchedule::CLASS_EQUITY,
            ),
        );
    }

    /**
     * Itemised cost of one side of one trade.
     *
     * @param  string  $side  self::SIDE_BUY or self::SIDE_SELL
     * @param  Carbon|null  $date  Trade date, used only to pick the schedule in
     *                             force. Null means today.
     * @param  string|null  $instrumentClass  `stocks.instrument_type`; an unmapped
     *                                        class falls back to the default class and says so.
     */
    public function compute(
        string $side,
        float $quantity,
        float $price,
        ?Carbon $date = null,
        ?string $instrumentClass = null,
    ): TransactionCostBreakdown {
        $side = $side === self::SIDE_SELL ? self::SIDE_SELL : self::SIDE_BUY;
        $requested = $instrumentClass ?? $this->defaultInstrumentClass;
        $date = ($date ?? Carbon::today())->copy()->startOfDay();

        [$schedule, $note] = $this->resolveSchedule($requested, $date);
        $turnover = round(max(0.0, $quantity) * max(0.0, $price), 2);

        if ($turnover <= 0.0) {
            // No turnover is not a free trade — it is not a trade. Charging a
            // DP fee or a minimum commission on it would invent a cost.
            return new TransactionCostBreakdown(
                side: $side,
                instrumentClass: $schedule->instrumentClass,
                quantity: round($quantity, 2),
                price: round($price, 4),
                turnover: 0.0,
                commission: 0.0,
                sebonFee: 0.0,
                dpCharge: 0.0,
                total: 0.0,
                tierLabel: null,
                tierRatePercent: null,
                application: $schedule->application,
                scheduleEffectiveFrom: $schedule->effectiveFrom->toDateString(),
                scheduleSource: $schedule->source,
                minimumCommissionApplied: false,
                note: 'Zero turnover — no cost is charged on a trade of no value.',
            );
        }

        [$commission, $tier, $minimumApplied] = $this->commission($schedule, $turnover);
        $sebon = round($turnover * $schedule->sebonFeePercent / 100, 2);
        $dp = $schedule->chargesDpOn($side) ? round($schedule->dpCharge, 2) : 0.0;

        return new TransactionCostBreakdown(
            side: $side,
            instrumentClass: $schedule->instrumentClass,
            quantity: round($quantity, 2),
            price: round($price, 4),
            turnover: $turnover,
            commission: $commission,
            sebonFee: $sebon,
            dpCharge: $dp,
            // Summed from the already-rounded parts so the itemisation always
            // adds up to the total a user is shown.
            total: round($commission + $sebon + $dp, 2),
            tierLabel: $tier?->label,
            tierRatePercent: $tier?->ratePercent,
            application: $schedule->application,
            scheduleEffectiveFrom: $schedule->effectiveFrom->toDateString(),
            scheduleSource: $schedule->source,
            minimumCommissionApplied: $minimumApplied,
            note: $note,
        );
    }

    /**
     * Both sides of a round trip, and what the pair costs in total.
     *
     * The figure that matters to a backtest or a paper fill is not either side
     * alone but the round-trip drag as a percentage of what was put in, which
     * is what has to be cleared before a trade makes anything.
     *
     * @return array{buy: TransactionCostBreakdown, sell: TransactionCostBreakdown, total_cost: float, round_trip_percent: ?float}
     */
    public function roundTrip(
        float $quantity,
        float $buyPrice,
        float $sellPrice,
        ?Carbon $buyDate = null,
        ?Carbon $sellDate = null,
        ?string $instrumentClass = null,
    ): array {
        $buy = $this->compute(self::SIDE_BUY, $quantity, $buyPrice, $buyDate, $instrumentClass);
        $sell = $this->compute(self::SIDE_SELL, $quantity, $sellPrice, $sellDate ?? $buyDate, $instrumentClass);
        $total = round($buy->total + $sell->total, 2);

        return [
            'buy' => $buy,
            'sell' => $sell,
            'total_cost' => $total,
            // Against the buy turnover — the capital actually committed.
            'round_trip_percent' => $buy->turnover > 0.0
                ? round($total / $buy->turnover * 100, 4)
                : null,
        ];
    }

    /**
     * Compare a recorded fee against what the schedule says it should be.
     *
     * Read-only by design. This is how a computed cost reaches a user without
     * touching a stored cost basis: it reports the variance and leaves the
     * ledger exactly as it is. `variance` is positive when the schedule is
     * dearer than what was recorded.
     *
     * @return array<string, mixed>
     */
    public function reconcile(
        string $side,
        float $quantity,
        float $price,
        float $recordedFees,
        ?Carbon $date = null,
        ?string $instrumentClass = null,
    ): array {
        $computed = $this->compute($side, $quantity, $price, $date, $instrumentClass);
        $recorded = round($recordedFees, 2);
        $variance = round($computed->total - $recorded, 2);

        return [
            'recorded_fees' => $recorded,
            'computed' => $computed->toArray(),
            'variance' => $variance,
            'variance_percent_of_turnover' => $computed->turnover > 0.0
                ? round($variance / $computed->turnover * 100, 4)
                : null,
            // A recorded zero is the common case for an imported or
            // hand-entered trade. It is not evidence that the trade was free,
            // so it is called out rather than reported as a clean match.
            'recorded_is_zero' => $recorded <= 0.0,
            'matches' => abs($variance) < 0.01,
        ];
    }

    /**
     * Commission under the schedule's application mode, floored in rupees.
     *
     * @return array{0: float, 1: ?CommissionTier, 2: bool}
     */
    private function commission(TransactionCostSchedule $schedule, float $turnover): array
    {
        $tier = $schedule->tierFor($turnover);

        if ($schedule->application === TransactionCostSchedule::APPLICATION_PROGRESSIVE) {
            $raw = 0.0;

            foreach ($schedule->tiers as $band) {
                $raw += $band->overlapWith($turnover) * $band->ratePercent / 100;
            }
        } else {
            $raw = $tier === null ? 0.0 : $turnover * $tier->ratePercent / 100;
        }

        $raw = round($raw, 2);
        $floor = round($schedule->minimumCommission, 2);

        return $raw < $floor
            ? [$floor, $tier, true]
            : [$raw, $tier, false];
    }

    /**
     * The schedule in force for an instrument class on a date.
     *
     * Two fallbacks, both reported rather than silent: an instrument class
     * with no schedule of its own is priced on the default class, and a trade
     * predating every configured schedule is priced on the oldest one — the
     * alternative being to refuse to cost a 2023 trade at all, which is worse
     * than costing it on the earliest known rates and saying so.
     *
     * @return array{0: TransactionCostSchedule, 1: ?string}
     */
    private function resolveSchedule(string $instrumentClass, Carbon $date): array
    {
        $note = null;
        $matching = $this->forClass($instrumentClass);

        if ($matching === []) {
            $matching = $this->forClass($this->defaultInstrumentClass);
            $note = sprintf(
                'No cost schedule for instrument class "%s"; priced on the "%s" schedule.',
                $instrumentClass,
                $this->defaultInstrumentClass,
            );
        }

        if ($matching === []) {
            // Neither the requested class nor the default is configured. Rather
            // than divide by an absent schedule, fall back to a zero-cost one
            // that says loudly that it costed nothing.
            return [
                TransactionCostSchedule::fromArray([
                    'instrument_class' => $instrumentClass,
                    'tiers' => [],
                    'minimum_commission' => 0.0,
                    'sebon_fee_percent' => 0.0,
                    'dp_charge' => 0.0,
                ], TransactionCostSchedule::SOURCE_BUILT_IN),
                'No cost schedule is configured at all; costs are reported as zero and are not a real figure.',
            ];
        }

        usort(
            $matching,
            static fn (TransactionCostSchedule $a, TransactionCostSchedule $b): int => $a->effectiveFrom <=> $b->effectiveFrom,
        );

        $chosen = null;

        foreach ($matching as $schedule) {
            if ($schedule->effectiveFrom->lessThanOrEqualTo($date)) {
                $chosen = $schedule;
            }
        }

        if ($chosen === null) {
            $chosen = $matching[0];
            $note = trim(($note ?? '').sprintf(
                ' Trade dated %s predates every configured schedule; priced on the earliest, effective %s.',
                $date->toDateString(),
                $chosen->effectiveFrom->toDateString(),
            ));
        }

        return [$chosen, $note === '' ? null : $note];
    }

    /**
     * @return list<TransactionCostSchedule>
     */
    private function forClass(string $instrumentClass): array
    {
        return array_values(array_filter(
            $this->schedules,
            static fn (TransactionCostSchedule $s): bool => $s->instrumentClass === $instrumentClass,
        ));
    }
}
