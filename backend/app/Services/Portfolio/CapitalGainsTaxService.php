<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Enums\AcquisitionChannel;
use App\Models\Portfolio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the year's realised gains will cost in tax.
 *
 * The Eloquent-facing half of the capital-gains estimator: it loads the
 * ledger, hands it to `PerformanceService::closedTrades()` for the FIFO round
 * trips, rates each one through the pure
 * {@see CapitalGainsTaxCalculator}, and buckets the results by **Nepali fiscal
 * year** — the period a Nepali capital-gains return and a broker statement are
 * actually drawn up over, so the numbers here can be checked against a
 * document rather than only believed.
 *
 * Period boundaries come from `PerformanceService::bucketKey()`, not from a
 * second copy of the fiscal-year rule, so a tax year and a performance year
 * are the same year by construction.
 *
 * Everything reported is an **estimate**. It is drawn from the ledger this app
 * holds, on rates that change by regulation, and it does not know about
 * anything the ledger does not record — another broker, a prior year's carried
 * position, or a disposal made outside this portfolio. It is stated as such in
 * the payload rather than left for a reader to assume.
 */
final class CapitalGainsTaxService
{
    /** The disposal list is a summary, not an export. */
    private const MAX_DISPOSALS = 250;

    public function __construct(
        private readonly PerformanceService $performance,
        private readonly CapitalGainsTaxCalculator $calculator = new CapitalGainsTaxCalculator,
    ) {}

    /**
     * @param  string|null  $investorClass  One of CapitalGainsTaxRates::investorClasses();
     *                                      null uses the configured default. Institutional rates
     *                                      differ and carry no holding-period split, which is why
     *                                      this is a parameter and not an assumption.
     * @return array<string, mixed>
     */
    public function report(
        Portfolio $portfolio,
        ?string $investorClass = null,
        string $basis = PerformanceService::BASIS_FISCAL,
    ): array {
        $basis = in_array($basis, [PerformanceService::BASIS_CALENDAR, PerformanceService::BASIS_FISCAL], true)
            ? $basis
            : PerformanceService::BASIS_FISCAL;

        $rates = CapitalGainsTaxRates::fromConfig($investorClass);

        $transactions = $portfolio->transactions()
            ->with('stock:id,symbol')
            ->orderBy('transacted_on')
            ->orderBy('id')
            ->get();

        $trades = $this->performance->closedTrades($transactions);
        $assessments = $this->calculator->assessAll($trades, $rates);

        return [
            'basis' => $basis,
            'investor_class' => $rates->investorClass,
            'rates' => $rates->toArray(),
            'periods' => $this->periods($assessments, $basis),
            'totals' => $this->calculator->aggregate($assessments),
            'disposals' => array_map(
                static fn (CapitalGainsTaxAssessment $a): array => $a->toArray(),
                array_slice(array_reverse($assessments), 0, self::MAX_DISPOSALS),
            ),
            'disposals_truncated' => count($assessments) > self::MAX_DISPOSALS,
            /*
             * Zero cost is not always a gap.
             *
             * A bonus share genuinely cost nothing, so a disposal of one
             * carries its whole proceeds as gain and that is the correct
             * answer, not a missing number. Without this distinction the
             * screen told the user to "set a cost" on a parcel they had just
             * finished confirming as bonus — an instruction that can never be
             * satisfied and never stops being shown.
             */
            'bonus_zero_cost_symbols' => $this->bonusZeroCostSymbols($portfolio),
            // Named, not merely counted. A user cannot fix a gap they cannot
            // see, and the fix here is a real one: add the missing purchase to
            // the ledger, or rebuild the portfolio.
            'excluded_disposals' => array_values(array_map(
                static fn (CapitalGainsTaxAssessment $a): array => [
                    'symbol' => $a->symbol,
                    'closed_on' => $a->closedOn,
                    'quantity' => $a->quantity,
                    'gross_gain' => $a->grossGain,
                    'reason' => $a->exclusionReason,
                    'explanation' => $a->rateReason,
                ],
                array_filter(
                    array_reverse($assessments),
                    static fn (CapitalGainsTaxAssessment $a): bool => ! $a->isAssessable,
                ),
            )),
            'fiscal_year_note' => $basis === PerformanceService::BASIS_FISCAL
                ? 'Fiscal years run Shrawan 1 to Ashad end, which is the period a Nepali '
                    .'capital-gains return covers. Boundaries come from a BS conversion table; '
                    .'override nepse.fiscal_year_starts if a year is wrong.'
                : 'Bucketed by Gregorian calendar year. Nepali capital gains are reported by '
                    .'fiscal year, so use basis=fiscal to match a return or a broker statement.',
            'estimate_note' => 'An estimate from this portfolio\'s ledger only, at the rates in '
                .'nepse.capital_gains_tax. It excludes disposals made elsewhere, and it is not '
                .'a substitute for the figure your broker withholds or your return reports.',
        ];
    }

    /**
     * Scrips whose zero-cost parcels are confirmed bonus issues.
     *
     * Only *confirmed* ones count — a channel the detector merely guessed at
     * is not evidence that the shares were free, and treating it as such would
     * quietly suppress a real gap.
     *
     * @return list<string>
     */
    private function bonusZeroCostSymbols(Portfolio $portfolio): array
    {
        return DB::table('portfolio_transactions')
            ->join('stocks', 'stocks.id', '=', 'portfolio_transactions.stock_id')
            ->where('portfolio_transactions.portfolio_id', $portfolio->id)
            ->where('portfolio_transactions.price', 0)
            ->where('portfolio_transactions.acquisition_channel', AcquisitionChannel::Bonus->value)
            ->whereNotNull('portfolio_transactions.channel_confirmed_at')
            ->distinct()
            ->pluck('stocks.symbol')
            ->map(static fn (mixed $v): string => (string) $v)
            ->values()
            ->all();
    }

    /**
     * One bucket per period a disposal closed in, newest first.
     *
     * Periods with no disposals are absent rather than present with zeros: a
     * year in which nothing was sold has no tax position, which is a different
     * statement from a year that realised nothing.
     *
     * @param  list<CapitalGainsTaxAssessment>  $assessments
     * @return list<array<string, mixed>>
     */
    private function periods(array $assessments, string $basis): array
    {
        /** @var array<string, array{key: array<string, mixed>, items: list<CapitalGainsTaxAssessment>}> $buckets */
        $buckets = [];

        foreach ($assessments as $assessment) {
            $key = PerformanceService::bucketKey(Carbon::parse($assessment->closedOn), $basis);
            $buckets[$key['key']] ??= ['key' => $key, 'items' => []];
            $buckets[$key['key']]['items'][] = $assessment;
        }

        ksort($buckets);
        $periods = [];

        foreach ($buckets as $bucket) {
            $periods[] = [
                'key' => (string) $bucket['key']['key'],
                'label' => $bucket['key']['label'],
                'starts_on' => $bucket['key']['starts_on'],
                'ends_on' => $bucket['key']['ends_on'],
                'approximate_boundary' => $bucket['key']['approximate'],
            ] + $this->calculator->aggregate($bucket['items']);
        }

        return array_reverse($periods);
    }
}
