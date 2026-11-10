<?php

declare(strict_types=1);

namespace App\Services\Scraper;

/**
 * Rejects scraped rows that are implausible as NEPSE trading data.
 *
 * The point is not to second-guess the market, it is to catch parse errors:
 * a shifted column turns a turnover figure into a price, and a price of
 * 25,249,128 for a bank would otherwise be written into the history and then
 * poison every moving average computed from it.
 */
final class PriceRowValidator
{
    /**
     * Longest calendar gap that can still separate two consecutive sessions.
     *
     * The market is closed Saturday and Sunday, so a Friday close is three days
     * older than the Monday that follows it. A stored close older than that
     * sits behind a session we never scraped, and is therefore not a
     * day-on-day reference.
     */
    private const MAX_SESSION_GAP_DAYS = 3;

    /**
     * @param  ?float  $previousClose  Most recent close already stored for this stock.
     * @param  ?int  $previousCloseAgeDays  Calendar days between that stored close and this row's session.
     * @return array{valid: bool, reason?: string}
     */
    public function validate(PriceRow $row, ?float $previousClose = null, ?int $previousCloseAgeDays = null): array
    {
        $rules = config('nepse.validation');

        if ($row->symbol === '' || strlen($row->symbol) > 24) {
            return ['valid' => false, 'reason' => 'implausible symbol'];
        }

        if ($row->close <= 0) {
            return ['valid' => false, 'reason' => 'close is zero or negative'];
        }

        if ($row->close < (float) $rules['min_price'] || $row->close > (float) $rules['max_price']) {
            return [
                'valid' => false,
                'reason' => sprintf(
                    'close %.2f outside plausible range %.2f-%.2f',
                    $row->close,
                    (float) $rules['min_price'],
                    (float) $rules['max_price'],
                ),
            ];
        }

        if ($row->volume < 0 || $row->turnover < 0) {
            return ['valid' => false, 'reason' => 'negative volume or turnover'];
        }

        // A day-on-day move far beyond the circuit breaker is almost always a
        // parse error or an unadjusted book-close price. The source's own
        // previous close is the better reference when it publishes one: the
        // stored close can be several sessions old whenever a scrape was
        // missed, and comparing across that gap measures the wrong thing.
        $reference = $row->previousClose ?? $previousClose;

        if ($reference !== null && $reference > 0) {
            $change = abs(($row->close - $reference) / $reference * 100);

            if ($change > (float) $rules['max_daily_change_percent']) {
                return [
                    'valid' => false,
                    'reason' => sprintf(
                        'close moved %.2f%% against previous close %.2f, above the %.2f%% limit',
                        $change,
                        $reference,
                        (float) $rules['max_daily_change_percent'],
                    ),
                ];
            }
        }

        // Cross-check the reported percentage against the arithmetic. A large
        // disagreement means the source's percent column was misread — this is
        // exactly how a rupee "Diff" once got stored as a percentage.
        //
        // The reported percent is a single-session move, so it can only be
        // checked against the close of the session immediately before. A stored
        // close from further back would make every stock that drifted since
        // then look mismapped, which is how a six-day scrape gap once rejected
        // half the market.
        $percentReference = $row->previousClose;

        if ($percentReference === null
            && $previousCloseAgeDays !== null
            && $previousCloseAgeDays <= self::MAX_SESSION_GAP_DAYS) {
            $percentReference = $previousClose;
        }

        if ($percentReference !== null && $percentReference > 0 && $row->changePercent !== null) {
            $computed = ($row->close - $percentReference) / $percentReference * 100;

            if (abs($computed - $row->changePercent) > 1.0) {
                return [
                    'valid' => false,
                    'reason' => sprintf(
                        'reported change of %.2f%% does not match the %.2f%% implied by close %.2f '
                        .'against previous close %.2f — the percent column is probably mismapped',
                        $row->changePercent,
                        $computed,
                        $row->close,
                        $percentReference,
                    ),
                ];
            }
        }

        return ['valid' => true];
    }

    /**
     * Repair a row whose intraday bar contradicts itself.
     *
     * ShareSansar publishes open=high=low for thinly traded debentures while
     * reporting a lower separately-determined close, so the bar is internally
     * inconsistent even though the close is right (it matches the last traded
     * price). Rejecting those rows outright would leave permanent holes in the
     * price history of every debenture, so the bar is dropped and the close
     * kept instead.
     *
     * @return array{row: PriceRow, repaired: bool, reason: ?string}
     */
    public function repair(PriceRow $row): array
    {
        if ($row->high === null || $row->low === null) {
            return ['row' => $row, 'repaired' => false, 'reason' => null];
        }

        $epsilon = 0.011;
        $reason = null;

        if ($row->high < $row->low - $epsilon) {
            $reason = 'high is below low';
        } elseif ($row->close > $row->high + $epsilon || $row->close < $row->low - $epsilon) {
            $reason = 'close falls outside the reported high-low range';
        } elseif ($row->open !== null && ($row->open > $row->high + $epsilon || $row->open < $row->low - $epsilon)) {
            $reason = 'open falls outside the reported high-low range';
        }

        if ($reason === null) {
            return ['row' => $row, 'repaired' => false, 'reason' => null];
        }

        return [
            'row' => $row->withoutIntradayBar(),
            'repaired' => true,
            'reason' => $reason,
        ];
    }
}
