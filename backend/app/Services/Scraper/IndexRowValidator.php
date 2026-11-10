<?php

declare(strict_types=1);

namespace App\Services\Scraper;

/**
 * Rejects scraped index readings that are implausible.
 *
 * Deliberately carries no absolute bounds on the index *level* — NEPSE Index
 * was under 200 in the late 1990s and over 3,000 twenty years later, and a
 * sector sub-index can be rebased at any time, so no constant survives the
 * data's own history. What is checked instead is relative movement (a day on
 * day move far beyond anything an index does) and internal consistency (a
 * reported percentage that disagrees with the arithmetic, or an OHLC bar that
 * contradicts itself) — the same shifted-column failure mode
 * {@see PriceRowValidator} exists to catch, applied to index rows.
 */
final class IndexRowValidator
{
    /**
     * Longest calendar gap that still lets a stored value serve as a
     * day-on-day reference. See {@see PriceRowValidator} for why this exists:
     * a close left behind by a missed scrape is not "yesterday" and comparing
     * against it measures the wrong thing.
     */
    private const MAX_SESSION_GAP_DAYS = 3;

    /**
     * @return array{valid: bool, reason?: string}
     */
    public function validate(
        string $name,
        float $value,
        ?float $reportedChangePercent = null,
        ?float $previousValue = null,
        ?int $previousValueAgeDays = null,
    ): array {
        $rules = config('nepse.index_validation');

        if ($value <= 0) {
            return ['valid' => false, 'reason' => "{$name}: value is zero or negative"];
        }

        $reference = ($previousValueAgeDays === null || $previousValueAgeDays <= self::MAX_SESSION_GAP_DAYS)
            ? $previousValue
            : null;

        if ($reference !== null && $reference > 0) {
            $change = abs(($value - $reference) / $reference * 100);

            if ($change > (float) $rules['max_daily_change_percent']) {
                return [
                    'valid' => false,
                    'reason' => sprintf(
                        '%s: value moved %.2f%% against previous value %.2f, above the %.2f%% limit',
                        $name,
                        $change,
                        $reference,
                        (float) $rules['max_daily_change_percent'],
                    ),
                ];
            }

            if ($reportedChangePercent !== null) {
                $computed = ($value - $reference) / $reference * 100;

                if (abs($computed - $reportedChangePercent) > 1.0) {
                    return [
                        'valid' => false,
                        'reason' => sprintf(
                            '%s: reported change of %.2f%% does not match the %.2f%% implied by value %.2f '
                            .'against previous value %.2f — a column is probably mismapped',
                            $name,
                            $reportedChangePercent,
                            $computed,
                            $value,
                            $reference,
                        ),
                    ];
                }
            }
        }

        return ['valid' => true];
    }

    /**
     * Repair a historical bar whose OHLC contradicts itself, keeping the
     * close (the figure every downstream benchmark actually uses) rather than
     * discarding the whole row over a bad open/high/low.
     *
     * @return array{bar: IndexHistoryBar, repaired: bool, reason: ?string}
     */
    public function repair(IndexHistoryBar $bar): array
    {
        if ($bar->high === null || $bar->low === null) {
            return ['bar' => $bar, 'repaired' => false, 'reason' => null];
        }

        $epsilon = 0.011;
        $reason = null;

        if ($bar->high < $bar->low - $epsilon) {
            $reason = 'high is below low';
        } elseif ($bar->value > $bar->high + $epsilon || $bar->value < $bar->low - $epsilon) {
            $reason = 'value falls outside the reported high-low range';
        } elseif ($bar->open !== null && ($bar->open > $bar->high + $epsilon || $bar->open < $bar->low - $epsilon)) {
            $reason = 'open falls outside the reported high-low range';
        }

        if ($reason === null) {
            return ['bar' => $bar, 'repaired' => false, 'reason' => null];
        }

        return [
            'bar' => new IndexHistoryBar(
                name: $bar->name,
                date: $bar->date,
                value: $bar->value,
                open: null,
                high: null,
                low: null,
                changeAmount: $bar->changeAmount,
                changePercent: $bar->changePercent,
                totalTurnover: $bar->totalTurnover,
            ),
            'repaired' => true,
            'reason' => $reason,
        ];
    }
}
