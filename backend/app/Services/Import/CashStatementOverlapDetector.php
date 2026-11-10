<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Guards against importing the same money twice.
 *
 * Two broker statements for one account are usually **sequential**, not
 * duplicated — the TMS log picks up where the account statement left off, and
 * a residual balance carried across the seam looks like the same amount on
 * both. So neither "these overlap in time" nor "these share an amount on a
 * day" can be treated as proof of a duplicate, and neither can be ignored:
 * silently double-counting a million rupees of deposits would invert every
 * money-weighted figure in the app, which is precisely the failure this
 * feature exists to fix.
 *
 * The rule is therefore three-tier, in decreasing confidence:
 *
 * 1. **Same fingerprint** — the same movement, certainly. A UUID transaction
 *    id is the movement's own identity, so a re-upload collides exactly.
 *    Excluded, silently and permanently.
 * 2. **Same day + same amount + same direction, different fingerprint** — a
 *    collision. Might be a genuine second 500-rupee top-up on the same day;
 *    might be the same movement re-exported without its id. Flagged
 *    `overlap_warning` and **excluded by default**: a missed row is a visible
 *    reconciliation gap the user can fix in one click, while a doubled row is
 *    a wrong answer nobody sees.
 * 3. **Date ranges intersect** — reported as an import-level warning only. It
 *    is normal for two statements to touch at a boundary, and excluding every
 *    row of a legitimately sequential statement over a one-day overlap would
 *    be worse than useless.
 *
 * Pure: arrays in, arrays out. No database, no clock.
 */
final class CashStatementOverlapDetector
{
    /** Amounts within this many rupees of each other count as the same amount. */
    private const AMOUNT_EPSILON = 0.005;

    /**
     * @param  list<array{fingerprint: ?string, occurred_on: ?string, amount: ?float, direction: ?string}>  $candidates
     *                                                                                                                   Rows staged for import.
     * @param  list<array{fingerprint: string, occurred_on: string, amount: float, direction: string, import_id?: int|string|null}>  $existing
     *                                                                                                                                          Movements already committed for this portfolio.
     * @return array{
     *     duplicates: array<string, string>,
     *     collisions: array<string, string>,
     *     range_overlap: ?array{from: string, to: string, existing_rows: int},
     *     candidate_window: ?array{from: string, to: string},
     *     existing_window: ?array{from: string, to: string},
     *     warnings: list<string>
     * }
     */
    public static function detect(array $candidates, array $existing): array
    {
        $byFingerprint = [];
        $byDayAmount = [];

        foreach ($existing as $row) {
            $byFingerprint[$row['fingerprint']] = true;
            $byDayAmount[self::dayAmountKey($row['occurred_on'], $row['amount'], $row['direction'])][] = $row;
        }

        $duplicates = [];
        $collisions = [];

        foreach ($candidates as $candidate) {
            $fingerprint = $candidate['fingerprint'] ?? null;

            if ($fingerprint === null) {
                // Not identifiable yet — the row is missing a field and is
                // already `needs_review`. Nothing to compare.
                continue;
            }

            if (isset($byFingerprint[$fingerprint])) {
                $duplicates[$fingerprint] = 'Already imported — the same transaction id is in the ledger.';

                continue;
            }

            $key = self::dayAmountKey(
                $candidate['occurred_on'] ?? null,
                $candidate['amount'] ?? null,
                $candidate['direction'] ?? null,
            );

            if ($key === null || ! isset($byDayAmount[$key])) {
                continue;
            }

            $collisions[$fingerprint] = sprintf(
                'A %s of NPR %s on %s is already in the ledger from another import. '
                .'If this is a second, genuinely separate movement, include it explicitly.',
                ($candidate['direction'] ?? '') === CashStatementParser::DIRECTION_OUT ? 'withdrawal' : 'deposit',
                number_format((float) ($candidate['amount'] ?? 0), 2),
                (string) ($candidate['occurred_on'] ?? ''),
            );
        }

        $candidateWindow = self::window(array_map(
            static fn (array $row): ?string => $row['occurred_on'] ?? null,
            $candidates,
        ));
        $existingWindow = self::window(array_map(
            static fn (array $row): ?string => $row['occurred_on'],
            $existing,
        ));

        $rangeOverlap = null;
        $warnings = [];

        if ($candidateWindow !== null && $existingWindow !== null) {
            $from = max($candidateWindow['from'], $existingWindow['from']);
            $to = min($candidateWindow['to'], $existingWindow['to']);

            if ($from <= $to) {
                $overlapping = array_values(array_filter(
                    $existing,
                    static fn (array $row): bool => $row['occurred_on'] >= $from && $row['occurred_on'] <= $to,
                ));

                $rangeOverlap = ['from' => $from, 'to' => $to, 'existing_rows' => count($overlapping)];
                $warnings[] = sprintf(
                    'This statement covers %s to %s, which overlaps %s to %s of what is already imported '
                    .'(%d movement%s in the overlapping window). Check the overlap before committing — '
                    .'two statements for one account are usually sequential, but importing the same '
                    .'stretch twice would double-count the capital.',
                    $candidateWindow['from'],
                    $candidateWindow['to'],
                    $from,
                    $to,
                    $rangeOverlap['existing_rows'],
                    $rangeOverlap['existing_rows'] === 1 ? '' : 's',
                );
            }
        }

        if ($duplicates !== []) {
            $warnings[] = sprintf(
                '%d movement%s already in the ledger and %s been skipped, so re-importing this '
                .'statement changes nothing.',
                count($duplicates),
                count($duplicates) === 1 ? ' is' : 's are',
                count($duplicates) === 1 ? 'has' : 'have',
            );
        }

        if ($collisions !== []) {
            $warnings[] = sprintf(
                '%d movement%s the same date, amount and direction as one already imported. '
                .'Excluded by default — include them only if they are genuinely separate movements.',
                count($collisions),
                count($collisions) === 1 ? ' has' : 's have',
            );
        }

        return [
            'duplicates' => $duplicates,
            'collisions' => $collisions,
            'range_overlap' => $rangeOverlap,
            'candidate_window' => $candidateWindow,
            'existing_window' => $existingWindow,
            'warnings' => $warnings,
        ];
    }

    private static function dayAmountKey(?string $date, ?float $amount, ?string $direction): ?string
    {
        if ($date === null || $amount === null || $direction === null) {
            return null;
        }

        // Rounded to the paisa so that 1272.0 and 1272.004 are the same
        // amount, which is what AMOUNT_EPSILON expresses.
        return implode('|', [
            $date,
            $direction,
            number_format(round($amount / self::AMOUNT_EPSILON) * self::AMOUNT_EPSILON, 2, '.', ''),
        ]);
    }

    /**
     * @param  array<int, ?string>  $dates
     * @return array{from: string, to: string}|null
     */
    private static function window(array $dates): ?array
    {
        $dates = array_values(array_filter($dates, static fn (?string $date): bool => $date !== null && $date !== ''));

        if ($dates === []) {
            return null;
        }

        // ISO dates sort lexicographically, so no date objects are needed —
        // which keeps this pure and free of timezone surprises.
        sort($dates);

        return ['from' => $dates[0], 'to' => $dates[count($dates) - 1]];
    }
}
