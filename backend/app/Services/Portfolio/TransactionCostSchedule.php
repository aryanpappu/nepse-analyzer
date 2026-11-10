<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use Illuminate\Support\Carbon;

/**
 * The full cost schedule for one instrument class, effective from one date.
 *
 * A NEPSE trade carries three separate charges, and only the first of them is
 * a percentage of anything the user would think to type in:
 *
 * - **Broker commission** — tiered on the transaction amount, stepping down
 *   from 0.36% to 0.24% as turnover rises, with a floor in rupees so a tiny
 *   trade is not charged a few paisa.
 * - **SEBON regulatory fee** — a flat 0.015% of turnover on equity.
 * - **DP charge** — a flat rupee amount per scrip per day, levied by the
 *   depository participant on both sides of a trade. It does not scale with
 *   size, which is exactly why it dominates the cost of a small trade.
 *
 * Every figure here is set by regulation and has changed before, so nothing is
 * decided in a service: a schedule is a value object built either from config
 * (`nepse.transaction_costs`) or, until that key exists, from
 * {@see nepseDefaults()} — the schedule in force at the time of writing,
 * labelled as a built-in default so a caller can tell a configured rate from
 * an assumed one.
 *
 * `effectiveFrom` exists because a cost schedule is only correct for trades on
 * or after the date the regulation took effect. Applying today's rates to a
 * 2023 trade would produce a confident wrong number, so the calculator picks
 * the newest schedule not later than the trade date and says which it used.
 *
 * `application` decides how a tier is applied to turnover:
 *
 * - `slab` (default) — the whole turnover is charged at the rate of the tier
 *   it falls in. This is how NEPSE commission calculators in common use behave.
 * - `progressive` — each slice of turnover is charged at its own tier's rate,
 *   the way income tax bands work.
 *
 * Both are supported because the published schedule is written as bands
 * without stating which reading applies, and the difference on a Rs 6 lakh
 * trade is real money. The mode is config-driven so a reading can be corrected
 * without a code change, and every result states which mode produced it.
 */
final class TransactionCostSchedule
{
    public const CLASS_EQUITY = 'equity';

    public const CLASS_MUTUAL_FUND = 'mutual_fund';

    public const CLASS_DEBENTURE = 'debenture';

    /** Whole turnover charged at the landing tier's rate. */
    public const APPLICATION_SLAB = 'slab';

    /** Each slice of turnover charged at its own tier's rate. */
    public const APPLICATION_PROGRESSIVE = 'progressive';

    public const SOURCE_CONFIG = 'config';

    public const SOURCE_BUILT_IN = 'built_in_default';

    public function __construct(
        public readonly string $instrumentClass,
        public readonly Carbon $effectiveFrom,
        /** @var list<CommissionTier> Ascending by turnover, contiguous. */
        public readonly array $tiers,
        public readonly float $minimumCommission,
        public readonly float $sebonFeePercent,
        public readonly float $dpCharge,
        public readonly bool $dpChargeOnBuy,
        public readonly bool $dpChargeOnSell,
        public readonly string $application,
        public readonly string $source,
    ) {}

    /**
     * The schedule in force at the time of writing, per instrument class.
     *
     * This is a *fallback*, not a policy: it exists so the calculator answers
     * correctly before `nepse.transaction_costs` is wired, and every breakdown
     * it produces is stamped `built_in_default` so a reader knows the rates
     * were assumed rather than configured.
     *
     * @return list<self>
     */
    public static function nepseDefaults(): array
    {
        $equityTiers = [
            ['up_to' => 50_000.0, 'rate_percent' => 0.36],
            ['up_to' => 500_000.0, 'rate_percent' => 0.33],
            ['up_to' => 2_000_000.0, 'rate_percent' => 0.31],
            ['up_to' => 10_000_000.0, 'rate_percent' => 0.27],
            ['up_to' => null, 'rate_percent' => 0.24],
        ];

        return [
            self::fromArray([
                'instrument_class' => self::CLASS_EQUITY,
                'effective_from' => '2000-01-01',
                'tiers' => $equityTiers,
                'minimum_commission' => 10.0,
                'sebon_fee_percent' => 0.015,
                'dp_charge' => 25.0,
            ], self::SOURCE_BUILT_IN),

            // Mutual fund units are commissioned at a single flat rate rather
            // than a tiered one, so the schedule is a one-tier schedule — the
            // same shape, no special case in the calculator.
            self::fromArray([
                'instrument_class' => self::CLASS_MUTUAL_FUND,
                'effective_from' => '2000-01-01',
                'tiers' => [['up_to' => null, 'rate_percent' => 0.15]],
                'minimum_commission' => 10.0,
                'sebon_fee_percent' => 0.015,
                'dp_charge' => 25.0,
            ], self::SOURCE_BUILT_IN),

            self::fromArray([
                'instrument_class' => self::CLASS_DEBENTURE,
                'effective_from' => '2000-01-01',
                'tiers' => [['up_to' => null, 'rate_percent' => 0.10]],
                'minimum_commission' => 10.0,
                'sebon_fee_percent' => 0.005,
                'dp_charge' => 25.0,
            ], self::SOURCE_BUILT_IN),
        ];
    }

    /**
     * Build one schedule from a plain config array.
     *
     * Pure — it takes the array rather than reading `config()` itself, so the
     * mapping between config shape and value object is unit-testable without a
     * framework and a caller can hand-build a schedule for a what-if.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw, string $source = self::SOURCE_CONFIG): self
    {
        /** @var list<array{up_to: float|int|null, rate_percent: float|int}> $rawTiers */
        $rawTiers = array_values((array) ($raw['tiers'] ?? []));
        $tiers = [];
        $floor = 0.0;

        foreach ($rawTiers as $tier) {
            $upTo = $tier['up_to'] ?? null;
            $ceiling = $upTo === null ? null : (float) $upTo;

            $tiers[] = new CommissionTier(
                minTurnover: $floor,
                maxTurnover: $ceiling,
                ratePercent: (float) $tier['rate_percent'],
                label: self::tierLabel($floor, $ceiling),
            );

            if ($ceiling === null) {
                break;
            }

            $floor = $ceiling;
        }

        $application = (string) ($raw['application'] ?? self::APPLICATION_SLAB);

        return new self(
            instrumentClass: (string) ($raw['instrument_class'] ?? self::CLASS_EQUITY),
            effectiveFrom: Carbon::parse((string) ($raw['effective_from'] ?? '2000-01-01'))->startOfDay(),
            tiers: $tiers,
            minimumCommission: (float) ($raw['minimum_commission'] ?? 0.0),
            sebonFeePercent: (float) ($raw['sebon_fee_percent'] ?? 0.0),
            dpCharge: (float) ($raw['dp_charge'] ?? 0.0),
            dpChargeOnBuy: (bool) ($raw['dp_charge_on_buy'] ?? true),
            dpChargeOnSell: (bool) ($raw['dp_charge_on_sell'] ?? true),
            application: in_array($application, [self::APPLICATION_SLAB, self::APPLICATION_PROGRESSIVE], true)
                ? $application
                : self::APPLICATION_SLAB,
            source: $source,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $raw
     * @return list<self>
     */
    public static function listFromArray(array $raw, string $source = self::SOURCE_CONFIG): array
    {
        return array_values(array_map(
            static fn (array $entry): self => self::fromArray($entry, $source),
            array_values($raw),
        ));
    }

    /**
     * The tier a turnover lands in under `slab` application.
     *
     * Null only for a zero or negative turnover, or a schedule configured with
     * no tiers at all — both of which the calculator reports as "no tier
     * applied" rather than guessing a rate.
     */
    public function tierFor(float $turnover): ?CommissionTier
    {
        if ($turnover <= 0.0) {
            return null;
        }

        foreach ($this->tiers as $tier) {
            if ($tier->contains($turnover)) {
                return $tier;
            }
        }

        // Turnover above the last bounded tier with no open-ended tier
        // configured: the top tier is the closest honest answer, and the
        // breakdown still names it so the gap in the schedule is visible.
        return $this->tiers === [] ? null : $this->tiers[count($this->tiers) - 1];
    }

    public function chargesDpOn(string $side): bool
    {
        return $side === TransactionCostCalculator::SIDE_SELL
            ? $this->dpChargeOnSell
            : $this->dpChargeOnBuy;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'instrument_class' => $this->instrumentClass,
            'effective_from' => $this->effectiveFrom->toDateString(),
            'application' => $this->application,
            'source' => $this->source,
            'minimum_commission' => $this->minimumCommission,
            'sebon_fee_percent' => $this->sebonFeePercent,
            'dp_charge' => $this->dpCharge,
            'dp_charge_on_buy' => $this->dpChargeOnBuy,
            'dp_charge_on_sell' => $this->dpChargeOnSell,
            'tiers' => array_map(static fn (CommissionTier $t): array => $t->toArray(), $this->tiers),
        ];
    }

    private static function tierLabel(float $floor, ?float $ceiling): string
    {
        if ($ceiling === null) {
            return sprintf('above NPR %s', number_format($floor));
        }

        if ($floor <= 0.0) {
            return sprintf('up to NPR %s', number_format($ceiling));
        }

        return sprintf('NPR %s to %s', number_format($floor + 1), number_format($ceiling));
    }
}
