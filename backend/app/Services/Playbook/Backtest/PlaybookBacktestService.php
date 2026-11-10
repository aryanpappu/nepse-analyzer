<?php

declare(strict_types=1);

namespace App\Services\Playbook\Backtest;

use App\Enums\InvestorProfile;
use App\Enums\VerdictGrade;
use App\Models\Stock;
use App\Models\StockFundamental;
use App\Models\TechnicalIndicator;
use App\Services\Playbook\PlaybookEngine;
use App\Services\Portfolio\IndexSeries;
use App\Services\Portfolio\IndexSeriesRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Replays the playbook over a historical window and measures what following it
 * would have done.
 *
 * The shape of the loop is what keeps it honest. For each eligible scrip the
 * whole bar history is loaded **once**, as a {@see BarSeries}. Then, for each
 * signal date, the series is cut in two: `upTo($date)` goes to the scoring
 * half, `after($date)` to the simulating half. The engine therefore cannot see
 * the bars the simulator is about to walk, and the simulator cannot see the
 * bar the engine scored on — not by discipline, but because the objects it is
 * handed do not contain them. See {@see HistoricalPlaybookInputFactory} for the
 * five look-ahead channels in the live input factory that this closes.
 *
 * One scrip at a time also means memory stays flat over a 110,000-bar table,
 * and the query count is two per chunk rather than two per (scrip, date).
 */
final class PlaybookBacktestService
{
    /** Stocks per chunk. Bars and indicators for a chunk are loaded in one query each. */
    private const CHUNK = 25;

    public function __construct(
        private readonly PlaybookEngine $engine,
        private readonly HistoricalPlaybookInputFactory $inputs,
        private readonly TradeSimulator $simulator,
        private readonly SessionCalendarReader $calendar,
        private readonly IndexSeriesRepository $indexRepository,
        private readonly IndexTradeComparator $indexComparator,
        private readonly BacktestStatistics $statistics,
        private readonly TradeCostCalculator $costs,
    ) {}

    /**
     * @param  (callable(string, int): void)|null  $onStock  Symbol, plans emitted — for a progress bar.
     */
    public function run(BacktestSettings $settings, ?callable $onStock = null): BacktestResult
    {
        $started = microtime(true);

        $calendar = $this->calendar->sessionsBetween($settings->from, $settings->to);
        $sessions = $calendar['dates'];
        $signalDates = $this->sampleSignalDates($sessions, $settings->intervalSessions);

        $universe = $this->universe($settings);
        $excluded = $this->excludedByInstrument($settings);

        /** @var list<SimulatedTrade> $trades */
        $trades = [];
        $skipReasons = [];
        $pairsWithBar = 0;
        $pairsWithIndicator = 0;
        $pairsWithFundamental = 0;
        $verdicts = 0;
        $withoutGeometry = 0;
        $stocksScored = 0;
        $latestBarDate = null;

        foreach ($universe->chunk(self::CHUNK) as $chunk) {
            /** @var Collection<int, Stock> $chunk */
            $ids = $chunk->pluck('id')->all();
            $barsByStock = $this->loadBars($ids);
            $indicatorsByStock = $this->loadIndicators($ids);
            $fundamentalsByStock = $this->loadFundamentals($ids, $settings);

            foreach ($chunk as $stock) {
                $series = $barsByStock[$stock->id] ?? BarSeries::empty();

                if ($series->isEmpty()) {
                    $skipReasons['no bars stored'] = ($skipReasons['no bars stored'] ?? 0) + 1;
                    if ($onStock !== null) {
                        $onStock($stock->symbol, 0);
                    }

                    continue;
                }

                $last = $series->lastDate();

                if ($last !== null && ($latestBarDate === null || $last > $latestBarDate)) {
                    $latestBarDate = $last;
                }

                $emitted = 0;

                foreach ($signalDates as $date) {
                    $history = $series->upTo($date);

                    if ($history->barOn($date) === null) {
                        $skipReasons['no bar on the signal date'] = ($skipReasons['no bar on the signal date'] ?? 0) + 1;

                        continue;
                    }

                    $pairsWithBar++;

                    $indicator = $this->indicatorAsAt($indicatorsByStock[$stock->id] ?? [], $date);

                    if ($indicator !== null) {
                        $pairsWithIndicator++;
                    }

                    $fundamental = $this->fundamentalAsAt($fundamentalsByStock[$stock->id] ?? [], $date, $settings);

                    if ($fundamental !== null) {
                        $pairsWithFundamental++;
                    }

                    $inputs = $this->inputs->build($stock, $history, $date, $indicator, $fundamental);

                    if ($inputs === null) {
                        $skipReasons['no usable close on the signal date'] = ($skipReasons['no usable close on the signal date'] ?? 0) + 1;

                        continue;
                    }

                    // Cut once per date, not once per profile.
                    $forward = $series->after($date);

                    foreach ($settings->profiles as $profile) {
                        $verdict = $this->engine->evaluate($inputs, $profile);

                        if ($verdict === null) {
                            $reason = $inputs->sessions < $profile->minHistorySessions()
                                ? sprintf('%s: fewer than %d sessions of history', $profile->value, $profile->minHistorySessions())
                                : sprintf('%s: no scoreable bucket', $profile->value);
                            $skipReasons[$reason] = ($skipReasons[$reason] ?? 0) + 1;

                            continue;
                        }

                        $verdicts++;

                        $plan = TradePlan::fromVerdict(
                            $verdict,
                            $date,
                            $settings->entryWindowSessions,
                            $settings->maxHoldSessions,
                            $settings->exitTarget,
                        );

                        if ($plan === null) {
                            $withoutGeometry++;

                            continue;
                        }

                        $trades[] = $this->simulator->simulate(
                            $plan,
                            $forward,
                            new TradeSubject($stock->symbol, $profile, $verdict->grade, $verdict->score),
                            $settings->notionalPerTrade,
                        );
                        $emitted++;
                    }
                }

                if ($emitted > 0) {
                    $stocksScored++;
                }

                if ($onStock !== null) {
                    $onStock($stock->symbol, $emitted);
                }
            }
        }

        return $this->assemble(
            $settings,
            $trades,
            $started,
            $calendar,
            $sessions,
            $signalDates,
            $calendar['suspected_gaps'],
            $universe->count(),
            $stocksScored,
            $excluded,
            $skipReasons,
            $pairsWithBar,
            $pairsWithIndicator,
            $pairsWithFundamental,
            $verdicts,
            $withoutGeometry,
            $latestBarDate,
        );
    }

    // ------------------------------------------------------------------
    // Assembly
    // ------------------------------------------------------------------

    /**
     * @param  list<SimulatedTrade>  $trades
     * @param  array{dates: list<string>, source: string, manual_trading_days: int, manual_holidays: int}  $calendar
     * @param  list<string>  $sessions
     * @param  list<string>  $signalDates
     * @param  list<string>  $calendarGaps
     * @param  array<string, int>  $excluded
     * @param  array<string, int>  $skipReasons
     */
    private function assemble(
        BacktestSettings $settings,
        array $trades,
        float $started,
        array $calendar,
        array $sessions,
        array $signalDates,
        array $calendarGaps,
        int $eligible,
        int $stocksScored,
        array $excluded,
        array $skipReasons,
        int $pairsWithBar,
        int $pairsWithIndicator,
        int $pairsWithFundamental,
        int $verdicts,
        int $withoutGeometry,
        ?string $latestBarDate,
    ): BacktestResult {
        $slug = $this->indexRepository->marketSlug($settings->marketIndexSlug);
        $loaded = $this->indexRepository->load($slug, $settings->to);
        /** @var IndexSeries $series */
        $series = $loaded['series'];

        $byGrade = [];
        $indexByGrade = [];
        $byProfileGrade = [];
        $indexByProfileGrade = [];

        foreach (VerdictGrade::cases() as $grade) {
            $slice = $this->filter($trades, null, $grade);
            $byGrade[$grade->value] = $this->statistics->summarize($slice);
            $indexByGrade[$grade->value] = $this->indexComparator->compare(
                $series, $slice, $settings->from, $settings->to, $slug, $loaded['name'],
            );
        }

        $byProfile = [];

        foreach ($settings->profiles as $profile) {
            $byProfile[$profile->value] = $this->statistics->summarize($this->filter($trades, $profile, null));
            $byProfileGrade[$profile->value] = [];
            $indexByProfileGrade[$profile->value] = [];

            foreach (VerdictGrade::cases() as $grade) {
                $slice = $this->filter($trades, $profile, $grade);
                $byProfileGrade[$profile->value][$grade->value] = $this->statistics->summarize($slice);
                $indexByProfileGrade[$profile->value][$grade->value] = $this->indexComparator->compare(
                    $series, $slice, $settings->from, $settings->to, $slug, $loaded['name'],
                );
            }
        }

        $resolved = count(array_filter($trades, static fn (SimulatedTrade $t): bool => $t->isResolved()));
        $noFill = count(array_filter($trades, static fn (SimulatedTrade $t): bool => $t->outcome === TradeOutcome::NoFill));
        $unresolved = count(array_filter($trades, static fn (SimulatedTrade $t): bool => $t->outcome === TradeOutcome::Unresolved));

        $indexOverall = $this->indexComparator->compare(
            $series, $trades, $settings->from, $settings->to, $slug, $loaded['name'],
        );

        $coverage = new BacktestCoverage(
            from: $settings->from->toDateString(),
            to: $settings->to->toDateString(),
            sessionsObserved: count($sessions),
            sessionSource: $calendar['source'],
            signalDates: count($signalDates),
            suspectedCalendarGaps: $calendarGaps,
            latestBarDate: $latestBarDate,
            stocksEligible: $eligible,
            stocksScored: $stocksScored,
            excludedByInstrument: $excluded,
            stockDatePairsPossible: $eligible * count($signalDates),
            stockDatePairsWithBar: $pairsWithBar,
            stockDatePairsWithIndicator: $pairsWithIndicator,
            stockDatePairsWithFundamental: $pairsWithFundamental,
            skipReasons: $skipReasons,
            verdicts: $verdicts,
            verdictsWithoutGeometry: $withoutGeometry,
            plansSimulated: count($trades),
            tradesResolved: $resolved,
            tradesNoFill: $noFill,
            tradesUnresolved: $unresolved,
            indexCoveragePercent: $indexOverall->coveragePercent,
            indexSimulationComplete: $indexOverall->windowComplete && $indexOverall->unmatchedTrades === 0,
            indexUncoveredDates: $indexOverall->uncoveredDates,
            indexCoverageNote: $this->indexNote($indexOverall, $slug),
            fundamentalsMode: $settings->fundamentalsMode->label(),
            lookAheadAllowed: $settings->fundamentalsMode->isLookAhead(),
        );

        return new BacktestResult(
            coverage: $coverage,
            overall: $this->statistics->summarize($trades),
            byGrade: $byGrade,
            byProfile: $byProfile,
            byProfileGrade: $byProfileGrade,
            indexOverall: $indexOverall,
            indexByGrade: $indexByGrade,
            indexByProfileGrade: $indexByProfileGrade,
            costSchedule: $this->costs->describe(),
            settings: $settings->toArray(),
            durationSeconds: round(microtime(true) - $started, 2),
        );
    }

    private function indexNote(IndexComparison $comparison, string $slug): string
    {
        if ($comparison->coveragePercent === null) {
            return sprintf('No index history is stored for "%s", so no market comparison was made — reported as n/a, not 0%%.', $slug);
        }

        $note = sprintf('Index "%s" covers %s%% of the window.', $slug, number_format($comparison->coveragePercent, 1));

        if ($comparison->coveragePercent < 99.5) {
            $note .= ' Incomplete index history can reverse the comparison; treat this result as provisional until coverage is complete.';
        }

        if ($comparison->unmatchedTrades > 0) {
            $note .= sprintf(' %s resolved trades could not be priced against the index and are excluded from the'
                .' matched-window figures rather than benchmarked at 0%%.', number_format($comparison->unmatchedTrades));
        }

        return $note;
    }

    /**
     * @param  list<SimulatedTrade>  $trades
     * @return list<SimulatedTrade>
     */
    private function filter(array $trades, ?InvestorProfile $profile, ?VerdictGrade $grade): array
    {
        return array_values(array_filter($trades, static function (SimulatedTrade $trade) use ($profile, $grade): bool {
            if ($profile !== null && $trade->subject->profile !== $profile) {
                return false;
            }

            return $grade === null || $trade->subject->grade === $grade;
        }));
    }

    // ------------------------------------------------------------------
    // Universe and loading
    // ------------------------------------------------------------------

    /**
     * @return Collection<int, Stock>
     */
    private function universe(BacktestSettings $settings): Collection
    {
        $query = Stock::query()
            ->whereIn('instrument_type', $settings->instrumentTypes)
            ->orderBy('symbol');

        if ($settings->symbols !== []) {
            $query->whereIn('symbol', $settings->symbols);
        }

        if ($settings->stockLimit !== null) {
            $query->limit($settings->stockLimit);
        }

        // Deliberately not scoped to `active()`: a scrip delisted since the
        // window would be invisible to a live run but was perfectly tradeable
        // at the time, and dropping it is textbook survivorship bias.
        return $query->get(['id', 'symbol', 'name', 'instrument_type', 'sector_id']);
    }

    /**
     * Debentures, government bonds, preference and promoter shares and fund
     * units are excluded from equity analysis by design. Counting them here
     * means the report says so instead of a reader wondering where 83 scrips
     * went.
     *
     * @return array<string, int>
     */
    private function excludedByInstrument(BacktestSettings $settings): array
    {
        return DB::table('stocks')
            ->select('instrument_type', DB::raw('count(*) as total'))
            ->whereNotIn('instrument_type', $settings->instrumentTypes)
            ->groupBy('instrument_type')
            ->pluck('total', 'instrument_type')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, BarSeries>
     */
    private function loadBars(array $ids): array
    {
        $rows = DB::table('stock_prices')
            ->select('stock_id', 'trade_date', 'open', 'high', 'low', 'close', 'volume')
            ->whereIn('stock_id', $ids)
            ->orderBy('stock_id')
            ->orderBy('trade_date')
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row->stock_id][] = new Bar(
                date: Carbon::parse((string) $row->trade_date)->toDateString(),
                close: (float) $row->close,
                open: $row->open === null ? null : (float) $row->open,
                high: $row->high === null ? null : (float) $row->high,
                low: $row->low === null ? null : (float) $row->low,
                volume: $row->volume === null ? null : (float) $row->volume,
            );
        }

        return array_map(static fn (array $bars): BarSeries => new BarSeries($bars), $grouped);
    }

    /**
     * Indicator rows keyed by stock then date. Loaded whole rather than
     * queried per date: the engine needs one row per signal date and there are
     * only a few hundred rows per scrip.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, TechnicalIndicator>>
     */
    private function loadIndicators(array $ids): array
    {
        $grouped = [];

        TechnicalIndicator::query()
            ->whereIn('stock_id', $ids)
            ->orderBy('stock_id')
            ->orderBy('trade_date')
            ->get()
            ->each(function (TechnicalIndicator $row) use (&$grouped): void {
                $grouped[(int) $row->stock_id][$row->trade_date->toDateString()] = $row;
            });

        return $grouped;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, list<StockFundamental>>
     */
    private function loadFundamentals(array $ids, BacktestSettings $settings): array
    {
        if ($settings->fundamentalsMode === FundamentalsMode::None) {
            return [];
        }

        $query = StockFundamental::query()->whereIn('stock_id', $ids);

        if ($settings->fundamentalsMode === FundamentalsMode::Dated) {
            $query->whereNotNull('reported_on')->orderBy('reported_on');
        } else {
            $query->orderBy('id');
        }

        $grouped = [];

        foreach ($query->get() as $row) {
            $grouped[(int) $row->stock_id][] = $row;
        }

        return $grouped;
    }

    /**
     * The most recent indicator row dated on or before $date. Never the
     * latest row in the table — that is look-ahead channel four.
     *
     * @param  array<string, TechnicalIndicator>  $rows  Keyed by date, ascending.
     */
    private function indicatorAsAt(array $rows, string $date): ?TechnicalIndicator
    {
        if (isset($rows[$date])) {
            return $rows[$date];
        }

        $found = null;

        foreach ($rows as $rowDate => $row) {
            if ($rowDate > $date) {
                break;
            }

            $found = $row;
        }

        return $found;
    }

    /**
     * @param  list<StockFundamental>  $rows
     */
    private function fundamentalAsAt(array $rows, string $date, BacktestSettings $settings): ?StockFundamental
    {
        if ($rows === [] || $settings->fundamentalsMode === FundamentalsMode::None) {
            return null;
        }

        if ($settings->fundamentalsMode === FundamentalsMode::Latest) {
            // Contaminated on purpose, and flagged as such all the way to the
            // report. The factory's date guard is bypassed by handing it a row
            // whose reported_on it can accept, or nothing at all.
            $usable = array_values(array_filter(
                $rows,
                static fn (StockFundamental $row): bool => $row->reported_on !== null
                    && $row->reported_on->toDateString() <= $date,
            ));

            return $usable === [] ? null : $usable[count($usable) - 1];
        }

        $found = null;

        foreach ($rows as $row) {
            if ($row->reported_on === null || $row->reported_on->toDateString() > $date) {
                continue;
            }

            $found = $row;
        }

        return $found;
    }

    /**
     * Every $interval-th observed session. Sampling every session triples the
     * work for a near-identical answer, because consecutive verdicts on the
     * same scrip overlap almost completely.
     *
     * @param  list<string>  $sessions
     * @return list<string>
     */
    private function sampleSignalDates(array $sessions, int $interval): array
    {
        if ($interval <= 1) {
            return $sessions;
        }

        $sampled = [];

        foreach ($sessions as $i => $date) {
            if ($i % $interval === 0) {
                $sampled[] = $date;
            }
        }

        return $sampled;
    }
}
