<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

/**
 * The capital-gains rate table for one class of investor.
 *
 * Nepal taxes gains on a share disposal at a rate that depends on how long the
 * shares were held: a resident individual pays 7.5% on a disposal inside 365
 * days and 5% at 365 days or more, while an institution pays a flat rate with
 * no holding-period distinction at all. The rate is withheld by the broker at
 * settlement, which is why this is worth estimating rather than discovering.
 *
 * The table is therefore *not* one pair of numbers. It is keyed by investor
 * class, and the threshold itself is a parameter — 365 days is current law, not
 * a law of nature, and a codebase that hardcodes it will report confidently
 * wrong tax the first time it moves. Everything here is config-driven with a
 * built-in fallback, and every assessment says which rate applied and why.
 *
 * `hasHoldingPeriodSplit()` is what makes the institutional case honest: it is
 * a flat rate, so an assessment must not claim a 400-day hold "earned" the
 * long-term rate when the same rate would have applied at 40 days.
 */
final class CapitalGainsTaxRates
{
    public const INVESTOR_INDIVIDUAL = 'individual';

    public const INVESTOR_INSTITUTION = 'institution';

    public const BAND_SHORT_TERM = 'short_term';

    public const BAND_LONG_TERM = 'long_term';

    public const BAND_FLAT = 'flat';

    public const SOURCE_CONFIG = 'config';

    public const SOURCE_BUILT_IN = 'built_in_default';

    /** Nepal's current holding-period boundary, in days. */
    public const DEFAULT_THRESHOLD_DAYS = 365;

    public function __construct(
        public readonly string $investorClass,
        /** Disposals at or above this many days held get the long-term rate. */
        public readonly int $longTermThresholdDays,
        public readonly float $shortTermRatePercent,
        public readonly float $longTermRatePercent,
        public readonly string $source,
        /** Human label for the class, for a UI that must not invent one. */
        public readonly string $label = '',
    ) {}

    /**
     * The table in force at the time of writing.
     *
     * @return array<string, self>
     */
    public static function builtInTable(): array
    {
        return [
            self::INVESTOR_INDIVIDUAL => new self(
                investorClass: self::INVESTOR_INDIVIDUAL,
                longTermThresholdDays: self::DEFAULT_THRESHOLD_DAYS,
                shortTermRatePercent: 7.5,
                longTermRatePercent: 5.0,
                source: self::SOURCE_BUILT_IN,
                label: 'Resident individual',
            ),
            // Flat: both bands carry the same rate, which is how a rate table
            // expresses "no holding-period distinction" without a special case
            // in the calculator.
            self::INVESTOR_INSTITUTION => new self(
                investorClass: self::INVESTOR_INSTITUTION,
                longTermThresholdDays: self::DEFAULT_THRESHOLD_DAYS,
                shortTermRatePercent: 10.0,
                longTermRatePercent: 10.0,
                source: self::SOURCE_BUILT_IN,
                label: 'Institution',
            ),
        ];
    }

    /**
     * Build one investor class's rates from a plain config array. Pure — the
     * caller reads config and hands the array over.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(string $investorClass, array $raw, string $source = self::SOURCE_CONFIG): self
    {
        $builtIn = self::builtInTable()[$investorClass] ?? self::builtInTable()[self::INVESTOR_INDIVIDUAL];

        return new self(
            investorClass: $investorClass,
            longTermThresholdDays: (int) ($raw['long_term_threshold_days'] ?? $builtIn->longTermThresholdDays),
            shortTermRatePercent: (float) ($raw['short_term_rate_percent'] ?? $builtIn->shortTermRatePercent),
            longTermRatePercent: (float) ($raw['long_term_rate_percent'] ?? $builtIn->longTermRatePercent),
            source: $source,
            label: (string) ($raw['label'] ?? $builtIn->label),
        );
    }

    /**
     * Resolve one investor class from `nepse.capital_gains_tax`, falling back
     * to the built-in table while that key does not exist.
     */
    public static function fromConfig(?string $investorClass = null): self
    {
        $default = (string) config(
            'nepse.capital_gains_tax.default_investor_class',
            self::INVESTOR_INDIVIDUAL,
        );

        $requested = $investorClass ?? $default;
        /** @var array<string, array<string, mixed>> $rates */
        $rates = (array) config('nepse.capital_gains_tax.rates', []);

        if (isset($rates[$requested]) && is_array($rates[$requested])) {
            return self::fromArray($requested, $rates[$requested]);
        }

        return self::builtInTable()[$requested]
            ?? self::builtInTable()[self::INVESTOR_INDIVIDUAL];
    }

    /**
     * @return list<string>
     */
    public static function investorClasses(): array
    {
        return array_keys(self::builtInTable());
    }

    public function hasHoldingPeriodSplit(): bool
    {
        return abs($this->shortTermRatePercent - $this->longTermRatePercent) > 1e-9;
    }

    /**
     * Which rate applies to a holding period, and the sentence explaining it.
     *
     * Null days held — a disposal the FIFO replay could not match to a
     * purchase — returns a null rate, not the short-term rate. A missing
     * holding period is missing data, and this codebase does not answer
     * missing data with the harsher of two numbers any more than with zero.
     *
     * @return array{band: ?string, rate_percent: ?float, reason: string}
     */
    public function bandFor(?int $daysHeld): array
    {
        if ($daysHeld === null) {
            return [
                'band' => null,
                'rate_percent' => null,
                'reason' => 'No acquisition date could be matched to this disposal, so the holding '
                    .'period — and therefore the rate — is unknown. Not taxed at the short-term '
                    .'rate by default: that would be a guess dressed as a figure.',
            ];
        }

        if (! $this->hasHoldingPeriodSplit()) {
            return [
                'band' => self::BAND_FLAT,
                'rate_percent' => $this->shortTermRatePercent,
                'reason' => sprintf(
                    '%s disposals are taxed at a flat %s%% regardless of holding period (held %d %s).',
                    $this->label === '' ? ucfirst($this->investorClass) : $this->label,
                    self::trim($this->shortTermRatePercent),
                    $daysHeld,
                    $daysHeld === 1 ? 'day' : 'days',
                ),
            ];
        }

        if ($daysHeld >= $this->longTermThresholdDays) {
            return [
                'band' => self::BAND_LONG_TERM,
                'rate_percent' => $this->longTermRatePercent,
                'reason' => sprintf(
                    'Held %d days, at or above the %d-day threshold, so the long-term rate of %s%% applies.',
                    $daysHeld,
                    $this->longTermThresholdDays,
                    self::trim($this->longTermRatePercent),
                ),
            ];
        }

        return [
            'band' => self::BAND_SHORT_TERM,
            'rate_percent' => $this->shortTermRatePercent,
            'reason' => sprintf(
                'Held %d days, under the %d-day threshold, so the short-term rate of %s%% applies.',
                $daysHeld,
                $this->longTermThresholdDays,
                self::trim($this->shortTermRatePercent),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'investor_class' => $this->investorClass,
            'label' => $this->label,
            'long_term_threshold_days' => $this->longTermThresholdDays,
            'short_term_rate_percent' => $this->shortTermRatePercent,
            'long_term_rate_percent' => $this->longTermRatePercent,
            'has_holding_period_split' => $this->hasHoldingPeriodSplit(),
            'source' => $this->source,
        ];
    }

    private static function trim(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
    }
}
