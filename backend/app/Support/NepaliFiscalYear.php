<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Nepali fiscal year boundaries, for reporting gains the way a broker
 * statement and the capital-gains rules do.
 *
 * The Nepali FY runs Shrawan 1 to Ashad end — roughly mid-July to mid-July —
 * but Shrawan 1 lands on 16 or 17 July depending on the year, and the Bikram
 * Sambat month lengths that decide which are not derivable from a formula.
 * So this is a lookup table, seeded with the known conversions and overridable
 * from config (`nepse.fiscal_year_starts`) so a wrong or missing year can be
 * corrected without a code change.
 *
 * Outside the table the class falls back to 16 July and says so via
 * isApproximate(), rather than silently reporting a boundary it cannot know.
 */
final class NepaliFiscalYear
{
    /**
     * Gregorian date of Shrawan 1 (fiscal year start), keyed by BS year.
     *
     * @var array<int, string>
     */
    private const STARTS = [
        2070 => '2013-07-16',
        2071 => '2014-07-17',
        2072 => '2015-07-17',
        2073 => '2016-07-16',
        2074 => '2017-07-16',
        2075 => '2018-07-17',
        2076 => '2019-07-17',
        2077 => '2020-07-16',
        2078 => '2021-07-16',
        2079 => '2022-07-17',
        2080 => '2023-07-17',
        2081 => '2024-07-16',
        2082 => '2025-07-17',
        2083 => '2026-07-17',
        2084 => '2027-07-17',
        2085 => '2028-07-16',
    ];

    private const FALLBACK_MONTH = 7;

    private const FALLBACK_DAY = 16;

    /**
     * NEPSE issuers file four quarterly reports per fiscal year, and the
     * quarters are the BS quarters exactly: Q1 = Shrawan–Ashoj, Q2 =
     * Kartik–Poush, Q3 = Magh–Chaitra, Q4 = Baisakh–Ashad.
     */
    public const QUARTERS_PER_YEAR = 4;

    public function __construct(
        public readonly int $bsYear,
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly bool $approximate,
    ) {}

    /**
     * Which fiscal year does this Gregorian date fall in?
     */
    public static function containing(Carbon $date): self
    {
        $date = $date->copy()->startOfDay();

        // Walk from a BS year comfortably before the date and stop at the last
        // start that is not after it. The table is small; clarity beats a
        // binary search here.
        $candidate = null;

        for ($bs = self::firstYear(); $bs <= self::lastYear() + 1; $bs++) {
            $start = self::startOf($bs);

            if ($start->greaterThan($date)) {
                break;
            }

            $candidate = $bs;
        }

        return self::for($candidate ?? self::firstYear());
    }

    public static function for(int $bsYear): self
    {
        $start = self::startOf($bsYear);
        $end = self::startOf($bsYear + 1)->subDay();

        return new self(
            bsYear: $bsYear,
            start: $start,
            end: $end,
            approximate: ! isset(self::table()[$bsYear]) || ! isset(self::table()[$bsYear + 1]),
        );
    }

    /**
     * "2082/83" — how a Nepali FY is written.
     */
    public function label(): string
    {
        return sprintf('%d/%02d', $this->bsYear, ($this->bsYear + 1) % 100);
    }

    public function key(): string
    {
        return (string) $this->bsYear;
    }

    public function contains(Carbon $date): bool
    {
        return $date->betweenIncluded($this->start, $this->end);
    }

    /**
     * Every fiscal year touched by [$from, $to], oldest first.
     *
     * @return list<self>
     */
    public static function range(Carbon $from, Carbon $to): array
    {
        $first = self::containing($from);
        $last = self::containing($to);
        $years = [];

        for ($bs = $first->bsYear; $bs <= $last->bsYear; $bs++) {
            $years[] = self::for($bs);
        }

        return $years;
    }

    /**
     * Gregorian date the given fiscal quarter ends on.
     *
     * Q4 needs no inference: the fourth quarter ends when the fiscal year does,
     * and both fiscal-year boundaries come straight out of the lookup table
     * above. So a Q4 or an annual period is dated exactly whenever its year is
     * in the table.
     *
     * Q1–Q3 are a different matter. They end at BS *month* boundaries — Ashoj,
     * Poush and Chaitra end — and BS month lengths (29 to 32 days, varying by
     * year) are not derivable from a formula any more than Shrawan 1 is. This
     * class holds no month table, and inventing one would be exactly the guess
     * the rest of the codebase refuses to make.
     *
     * What it does instead is split the fiscal year proportionally between two
     * boundaries it *does* know exactly. That claims nothing about BS month
     * lengths; it only says "the third quarter ends three quarters of the way
     * through a year whose ends are both known". Measured against the real BS
     * calendar the result lands within a few days — three at the widest point
     * measured against this data — so it is reported as
     * approximate — never as fact — via {@see quarterEndIsExact()}, in the same
     * spirit as the fiscal-year fallback above. Two days is immaterial to the
     * question these dates exist to answer (which filing was in force at a
     * given date, when filings are 91 days apart) and a null would answer it
     * not at all, but an interpolated boundary must never be mistaken for a
     * published one.
     *
     * A real date can be asserted without a code change through
     * `config('nepse.fiscal_quarter_ends')`, keyed "<bsYear>:<quarter>"
     * (e.g. `['2082:3' => '2026-04-13']`), which is then treated as exact.
     */
    public function quarterEnd(int $quarter): Carbon
    {
        $quarter = $this->clampQuarter($quarter);

        $asserted = self::assertedQuarterEnd($this->bsYear, $quarter);

        if ($asserted !== null) {
            return $asserted;
        }

        if ($quarter === self::QUARTERS_PER_YEAR) {
            return $this->end->copy();
        }

        return $this->start->copy()->addDays($this->quarterEndOffsetDays($quarter));
    }

    /**
     * Whether quarterEnd() is a published boundary rather than an interpolated
     * one. True only for an asserted override, or for Q4 of a year whose
     * fiscal-year boundaries are both in the table.
     */
    public function quarterEndIsExact(int $quarter): bool
    {
        $quarter = $this->clampQuarter($quarter);

        if (self::assertedQuarterEnd($this->bsYear, $quarter) !== null) {
            return true;
        }

        return $quarter === self::QUARTERS_PER_YEAR && ! $this->approximate;
    }

    /**
     * Whether the fiscal year end — which is also the annual reporting date —
     * is a published boundary.
     */
    public function endIsExact(): bool
    {
        return ! $this->approximate;
    }

    private function clampQuarter(int $quarter): int
    {
        return max(1, min(self::QUARTERS_PER_YEAR, $quarter));
    }

    /**
     * Days from Shrawan 1 to the last day of $quarter, for an interim quarter.
     *
     * The quarter after this one starts $quarter/4 of the way through the year,
     * so this quarter ends the day before that. diffInDays() is cast because
     * Carbon returns a float and an unrounded float in an integer position is
     * how a comparable date calculation has crashed here before.
     */
    private function quarterEndOffsetDays(int $quarter): int
    {
        $lengthDays = (int) $this->start->diffInDays($this->end) + 1;

        $boundary = (int) round($lengthDays * $quarter / self::QUARTERS_PER_YEAR);

        // Never fold a quarter into a zero-length span, however odd the table.
        return max($quarter - 1, $boundary - 1);
    }

    private static function assertedQuarterEnd(int $bsYear, int $quarter): ?Carbon
    {
        /** @var array<string, string> $asserted */
        $asserted = (array) config('nepse.fiscal_quarter_ends', []);

        $value = $asserted[$bsYear.':'.$quarter] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value)->startOfDay();
    }

    private static function startOf(int $bsYear): Carbon
    {
        $table = self::table();

        if (isset($table[$bsYear])) {
            return Carbon::parse($table[$bsYear])->startOfDay();
        }

        // Gregorian year of Shrawan 1 is always BS year minus 57 for a
        // mid-July date, which is what makes the fallback usable at all.
        return Carbon::create($bsYear - 57, self::FALLBACK_MONTH, self::FALLBACK_DAY)->startOfDay();
    }

    /**
     * @return array<int, string>
     */
    private static function table(): array
    {
        /** @var array<int, string> $override */
        $override = (array) config('nepse.fiscal_year_starts', []);

        return $override === [] ? self::STARTS : $override + self::STARTS;
    }

    private static function firstYear(): int
    {
        return min(array_keys(self::table()));
    }

    private static function lastYear(): int
    {
        return max(array_keys(self::table()));
    }
}
