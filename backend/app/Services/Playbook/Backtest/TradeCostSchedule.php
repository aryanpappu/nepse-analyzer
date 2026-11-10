<?php

declare(strict_types=1);

namespace App\Services\Playbook\Backtest;

/**
 * The fee schedule, as data.
 *
 * Separated from the calculator so the numbers can come from config (see
 * `nepse.backtest.costs`) or be written inline in a test, and so a tier table
 * can be diffed against a broker's published one without reading any logic.
 *
 * Rates are fractions (0.0036 = 0.36%). Tiers are read in order and the first
 * whose `up_to` is null or >= the turnover wins, so a table must be ascending.
 */
final class TradeCostSchedule
{
    /**
     * @param  list<array{up_to: ?float, rate: float}>  $commissionTiers  Ascending by `up_to`.
     */
    public function __construct(
        public readonly array $commissionTiers,
        public readonly float $minimumCommission,
        public readonly float $sebonFeeRate,
        public readonly float $dpChargePerSide,
        public readonly bool $dpChargeOnBuy,
        public readonly bool $dpChargeOnSell,
    ) {}

    /**
     * The equity schedule in force on NEPSE at the time of writing: tiered
     * broker commission with a Rs 10 floor, a 0.015% SEBON regulatory fee, and
     * a flat Rs 25 DP charge per side. On a Rs 100,000 round trip that is
     * roughly 0.74% all-in, inside the 0.6-1.2% band the roadmap quotes.
     *
     * These are defaults, not gospel — commission tiers have been revised more
     * than once and the roadmap tracks computing them from the real schedule.
     */
    public static function nepseEquityDefault(): self
    {
        return new self(
            commissionTiers: [
                ['up_to' => 50_000.0, 'rate' => 0.0036],
                ['up_to' => 500_000.0, 'rate' => 0.0033],
                ['up_to' => 2_000_000.0, 'rate' => 0.0031],
                ['up_to' => 10_000_000.0, 'rate' => 0.0027],
                ['up_to' => null, 'rate' => 0.0024],
            ],
            minimumCommission: 10.0,
            sebonFeeRate: 0.00015,
            dpChargePerSide: 25.0,
            dpChargeOnBuy: true,
            dpChargeOnSell: true,
        );
    }

    /**
     * @param  array<string, mixed>  $config  Shape mirrors `nepse.backtest.costs`; anything absent falls back to the default above.
     */
    public static function fromConfig(array $config): self
    {
        $default = self::nepseEquityDefault();

        $tiers = [];

        foreach ((array) ($config['commission_tiers'] ?? []) as $tier) {
            if (! is_array($tier) || ! array_key_exists('rate', $tier)) {
                continue;
            }

            $upTo = $tier['up_to'] ?? null;

            $tiers[] = [
                'up_to' => $upTo === null ? null : (float) $upTo,
                'rate' => (float) $tier['rate'],
            ];
        }

        return new self(
            commissionTiers: $tiers === [] ? $default->commissionTiers : $tiers,
            minimumCommission: (float) ($config['minimum_commission'] ?? $default->minimumCommission),
            sebonFeeRate: (float) ($config['sebon_fee_rate'] ?? $default->sebonFeeRate),
            dpChargePerSide: (float) ($config['dp_charge_per_side'] ?? $default->dpChargePerSide),
            dpChargeOnBuy: (bool) ($config['dp_charge_on_buy'] ?? $default->dpChargeOnBuy),
            dpChargeOnSell: (bool) ($config['dp_charge_on_sell'] ?? $default->dpChargeOnSell),
        );
    }

    /**
     * A schedule that charges nothing — the gross leg of the report, and the
     * control case in the cost tests. A named null object rather than a flag,
     * so "was this run costed?" is answerable by looking at the object.
     */
    public static function free(): self
    {
        return new self(
            commissionTiers: [['up_to' => null, 'rate' => 0.0]],
            minimumCommission: 0.0,
            sebonFeeRate: 0.0,
            dpChargePerSide: 0.0,
            dpChargeOnBuy: false,
            dpChargeOnSell: false,
        );
    }

    public function commissionRateFor(float $turnover): float
    {
        foreach ($this->commissionTiers as $tier) {
            if ($tier['up_to'] === null || $turnover <= $tier['up_to']) {
                return $tier['rate'];
            }
        }

        $last = $this->commissionTiers[count($this->commissionTiers) - 1] ?? ['rate' => 0.0];

        return $last['rate'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'commission_tiers' => array_map(static fn (array $tier): array => [
                'up_to' => $tier['up_to'],
                'rate_percent' => round($tier['rate'] * 100, 4),
            ], $this->commissionTiers),
            'minimum_commission' => $this->minimumCommission,
            'sebon_fee_percent' => round($this->sebonFeeRate * 100, 4),
            'dp_charge_per_side' => $this->dpChargePerSide,
            'dp_charge_on_buy' => $this->dpChargeOnBuy,
            'dp_charge_on_sell' => $this->dpChargeOnSell,
        ];
    }
}
