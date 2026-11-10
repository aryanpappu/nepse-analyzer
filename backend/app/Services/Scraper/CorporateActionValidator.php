<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use Illuminate\Support\Carbon;

/**
 * Rejects corporate-calendar rows that cannot be true.
 *
 * Same purpose as {@see PriceRowValidator}: not to second-guess the issuer, but
 * to catch a shifted or renamed field before it reaches the database. A book
 * close read out of the wrong column becomes an alert telling someone to buy
 * before a date that has no meaning, which is worse than no calendar at all.
 *
 * Deliberately no absolute date bounds. A calendar spans announced issues years
 * out and rights issues from 2011, so no fixed window survives the data — the
 * same reason {@see IndexRowValidator} carries no bound on an index level.
 * What is checked instead is:
 *
 * - **Internal consistency.** An issue cannot close before it opens, be
 *   allotted before it closes, or list before its book close. This is the
 *   `high >= close >= low` check applied to a timeline, and it is what actually
 *   catches a swapped pair of date columns.
 * - **Arithmetic the source publishes twice.** The dividend feed gives cash,
 *   bonus *and* total. A total that disagrees with its own parts means a column
 *   moved — exactly how a rupee figure once became a percentage on the price
 *   side.
 * - **Distance from a date the source itself stated**, not from the local
 *   clock. The feeds publish their own `published_date`, so "implausibly far
 *   out" is measured relative to that; with no reference the check is skipped
 *   rather than silently made against today.
 */
final class CorporateActionValidator
{
    /**
     * @param  ?Carbon  $publishedOn  The date the source says it published this
     *                                payload. Null skips the horizon check
     *                                entirely rather than substituting now().
     * @return array{valid: bool, reason?: string}
     */
    public function validate(CorporateActionRow $row, ?Carbon $publishedOn = null): array
    {
        $rules = (array) config('nepse.corporate_actions.validation', []);

        if ($row->symbol === '' || strlen($row->symbol) > 24) {
            return ['valid' => false, 'reason' => 'implausible symbol'];
        }

        if (! in_array($row->type, CorporateActionType::all(), true)) {
            return ['valid' => false, 'reason' => "unknown event type '{$row->type}'"];
        }

        // The one universal requirement. A row with no date is not a calendar
        // entry, and the rights feed publishes plenty of them for old issues
        // whose dates it never recorded.
        if (! $row->hasAnyDate()) {
            return ['valid' => false, 'reason' => 'no date on the record — nothing to put on a calendar'];
        }

        $ordering = $this->checkOrdering($row);

        if ($ordering !== null) {
            return ['valid' => false, 'reason' => $ordering];
        }

        $payout = $this->checkPayout($row, $rules);

        if ($payout !== null) {
            return ['valid' => false, 'reason' => $payout];
        }

        $amounts = $this->checkAmounts($row);

        if ($amounts !== null) {
            return ['valid' => false, 'reason' => $amounts];
        }

        $horizon = $this->checkHorizon($row, $publishedOn, $rules);

        if ($horizon !== null) {
            return ['valid' => false, 'reason' => $horizon];
        }

        return ['valid' => true];
    }

    /**
     * Repair a row whose *secondary* dates contradict its timeline.
     *
     * The same judgement {@see PriceRowValidator::repair()} makes about a
     * debenture whose close sits outside its own high/low: the record is not
     * worthless because one auxiliary field is wrong. ShareSansar's issue
     * archive carries a few dozen 2011–2014 rows whose `final_date` precedes
     * their `closing_date` — hand-entered legacy data the site never corrected
     * — and discarding those would lose a real IPO's opening and closing dates
     * over a field almost nothing reads.
     *
     * Only the secondary dates are droppable. A broken *primary* timeline
     * (an issue closing before it opens) is a different animal: that is the
     * calendar's whole content, and it is rejected by {@see validate()} rather
     * than trimmed into something that looks fine.
     *
     * @return array{row: CorporateActionRow, repaired: bool, reasons: list<string>}
     */
    public function repair(CorporateActionRow $row): array
    {
        $drop = [];
        $reasons = [];

        foreach ([
            ['final_on', $row->finalOn, 'closes_on', $row->closesOn],
            ['listing_on', $row->listingOn, 'book_close_on', $row->bookCloseOn],
            ['distribution_on', $row->distributionOn, 'book_close_on', $row->bookCloseOn],
        ] as [$laterName, $later, $earlierName, $earlier]) {
            if ($later === null || $earlier === null || ! $later->lessThan($earlier)) {
                continue;
            }

            $drop[] = $laterName;
            $reasons[] = sprintf(
                '%s (%s) precedes %s (%s) — dropped, the rest of the row kept',
                $laterName,
                $later->toDateString(),
                $earlierName,
                $earlier->toDateString(),
            );
        }

        if ($drop === []) {
            return ['row' => $row, 'repaired' => false, 'reasons' => []];
        }

        $repaired = $row->withoutDates(...$drop);

        // Dropping the only date a row had leaves nothing to calendar. Hand back
        // the original so validate() rejects it for what it is, rather than
        // reporting it as successfully repaired into emptiness.
        if (! $repaired->hasAnyDate()) {
            return ['row' => $row, 'repaired' => false, 'reasons' => []];
        }

        return ['row' => $repaired, 'repaired' => true, 'reasons' => $reasons];
    }

    /**
     * A primary timeline that runs backwards is a swapped pair of columns.
     *
     * Only pairs whose order is genuinely fixed are checked, and only the ones
     * worth rejecting a whole row over — the secondary dates are handled by
     * {@see repair()} instead. Notably a book close is *not* required to
     * precede a meeting: it usually does, but an issuer can and does move a
     * meeting to before a previously announced closure, and rejecting that
     * would drop real rows.
     */
    private function checkOrdering(CorporateActionRow $row): ?string
    {
        $pairs = [
            ['closes_on', $row->closesOn, 'event_on (opening)', $row->eventOn],
        ];

        foreach ($pairs as [$laterName, $later, $earlierName, $earlier]) {
            if ($later === null || $earlier === null) {
                continue;
            }

            if ($later->lessThan($earlier)) {
                return sprintf(
                    '%s (%s) is before %s (%s) — the date columns are probably swapped',
                    $laterName,
                    $later->toDateString(),
                    $earlierName,
                    $earlier->toDateString(),
                );
            }
        }

        return null;
    }

    /**
     * Cross-check the payout total against its own parts.
     *
     * The feed publishes cash, bonus and total, so the total is arithmetic we
     * can verify rather than a figure we have to trust. The tolerance is a
     * rounding allowance, not a fudge: the source rounds each part to four
     * decimals independently.
     *
     * @param  array<string, mixed>  $rules
     */
    private function checkPayout(CorporateActionRow $row, array $rules): ?string
    {
        foreach ([
            'cash dividend' => $row->cashDividendPercent,
            'bonus share' => $row->bonusPercent,
            'total dividend' => $row->totalDividendPercent,
        ] as $label => $value) {
            if ($value !== null && $value < 0) {
                return sprintf('%s is negative (%.4f)', $label, $value);
            }
        }

        if ($row->totalDividendPercent === null
            || ($row->cashDividendPercent === null && $row->bonusPercent === null)) {
            return null;
        }

        $parts = (float) $row->cashDividendPercent + (float) $row->bonusPercent;
        $tolerance = (float) ($rules['payout_tolerance_percent'] ?? 0.01);

        if (abs($parts - $row->totalDividendPercent) > $tolerance) {
            return sprintf(
                'total dividend %.4f%% does not match cash %.4f%% + bonus %.4f%% = %.4f%% — a column is probably mismapped',
                $row->totalDividendPercent,
                (float) $row->cashDividendPercent,
                (float) $row->bonusPercent,
                $parts,
            );
        }

        return null;
    }

    /**
     * Units and an issue price are counts and money: zero or negative means the
     * field was not what we thought it was.
     */
    private function checkAmounts(CorporateActionRow $row): ?string
    {
        if ($row->units !== null && $row->units <= 0) {
            return sprintf('total units is zero or negative (%.2f)', $row->units);
        }

        if ($row->issuePrice !== null && $row->issuePrice <= 0) {
            return sprintf('issue price is zero or negative (%.2f)', $row->issuePrice);
        }

        return null;
    }

    /**
     * Reject a date implausibly far from the date the source stated.
     *
     * Relative on both sides, and generous on the past side because the issue
     * feeds are a full archive: a rights issue from 2011 is real history, while
     * a book close a decade out is a misparse. Skipped entirely when the source
     * published no reference date.
     *
     * @param  array<string, mixed>  $rules
     */
    private function checkHorizon(CorporateActionRow $row, ?Carbon $publishedOn, array $rules): ?string
    {
        if ($publishedOn === null) {
            return null;
        }

        $reference = $publishedOn->copy()->startOfDay();
        $futureYears = (int) ($rules['max_future_years'] ?? 5);
        $pastYears = (int) ($rules['max_past_years'] ?? 40);

        $notAfter = $reference->copy()->addYears($futureYears);
        $notBefore = $reference->copy()->subYears($pastYears);

        foreach ($row->dates() as $field => $date) {
            if ($date->greaterThan($notAfter)) {
                return sprintf(
                    '%s (%s) is more than %d years after the source\'s own published date %s',
                    $field,
                    $date->toDateString(),
                    $futureYears,
                    $reference->toDateString(),
                );
            }

            if ($date->lessThan($notBefore)) {
                return sprintf(
                    '%s (%s) is more than %d years before the source\'s own published date %s',
                    $field,
                    $date->toDateString(),
                    $pastYears,
                    $reference->toDateString(),
                );
            }
        }

        return null;
    }
}
