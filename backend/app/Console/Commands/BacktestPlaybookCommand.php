<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InvestorProfile;
use App\Enums\VerdictGrade;
use App\Services\Playbook\Backtest\BacktestResult;
use App\Services\Playbook\Backtest\BacktestSettings;
use App\Services\Playbook\Backtest\BacktestStatistics;
use App\Services\Playbook\Backtest\FundamentalsMode;
use App\Services\Playbook\Backtest\HistoricalPlaybookInputFactory;
use App\Services\Playbook\Backtest\IndexComparison;
use App\Services\Playbook\Backtest\IndexTradeComparator;
use App\Services\Playbook\Backtest\PlaybookBacktestService;
use App\Services\Playbook\Backtest\ScheduledTradeCostCalculator;
use App\Services\Playbook\Backtest\SessionCalendarReader;
use App\Services\Playbook\Backtest\TradeCostSchedule;
use App\Services\Playbook\Backtest\TradeSimulator;
use App\Services\Playbook\Backtest\TradeStatistics;
use App\Services\Playbook\PlaybookEngine;
use App\Services\Portfolio\IndexReturnCalculator;
use App\Services\Portfolio\IndexSeriesRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Does the playbook work?
 *
 * Replays the engine as at each past session using only the data that existed
 * on that session, simulates the trade geometry it emitted, charges the round
 * trip, and compares the result to the NEPSE index over the same windows.
 *
 * Coverage is printed before performance, deliberately. A hit rate from a
 * slice that resolved 12% of its plans against 40% of the index window is not
 * a result. In a fictional benchmark, 60% coverage suggests NPR 2,000.00
 * of outperformance while complete history shows NPR 1,500.00 behind.
 */
class BacktestPlaybookCommand extends Command
{
    protected $signature = 'nepse:playbook-backtest
        {--from= : Window start (YYYY-MM-DD). Defaults to three years before --to.}
        {--to= : Window end (YYYY-MM-DD). Defaults to the latest stored session.}
        {--profile=* : Only these profiles (long_term, swing, momentum). Defaults to all.}
        {--symbol=* : Only these symbols. Defaults to every eligible equity.}
        {--interval= : Sessions between signal dates (default 10). 1 scores every session.}
        {--entry-window= : Sessions in which the entry band must trade before the plan lapses (default 5).}
        {--max-hold= : Override the profile hold ceiling, in sessions.}
        {--exit-target=target_1 : Which target closes the trade (target_1|target_2).}
        {--notional= : Rupees deployed per trade (default 100000).}
        {--fundamentals=none : none|dated|latest. "latest" is look-ahead contaminated and needs --allow-lookahead.}
        {--allow-lookahead : Permit the contaminated fundamentals mode. Never use for a real verdict.}
        {--index= : Market index slug. Defaults to nepse.benchmark.market_index_slug.}
        {--limit= : Cap the scrip universe, for a smoke run.}
        {--no-costs : Report the uncosted run only. Off by default — an uncosted backtest flatters every strategy.}
        {--json : Dump the full result as JSON instead of tables.}';

    protected $description = 'Backtest the playbook: replay past verdicts, simulate their trade geometry, and compare to the NEPSE index';

    public function handle(
        PlaybookEngine $engine,
        HistoricalPlaybookInputFactory $inputs,
        SessionCalendarReader $calendar,
        IndexSeriesRepository $indexRepository,
        IndexReturnCalculator $indexCalculator,
        BacktestStatistics $statistics,
    ): int {
        $settings = $this->settings();

        if ($settings === null) {
            return self::FAILURE;
        }

        $schedule = $this->option('no-costs') === true
            ? TradeCostSchedule::free()
            : TradeCostSchedule::fromConfig((array) config('nepse.backtest.costs', []));

        $costs = new ScheduledTradeCostCalculator($schedule);

        $service = new PlaybookBacktestService(
            engine: $engine,
            inputs: $inputs,
            simulator: new TradeSimulator($costs),
            calendar: $calendar,
            indexRepository: $indexRepository,
            indexComparator: new IndexTradeComparator($indexCalculator),
            statistics: $statistics,
            costs: $costs,
        );

        $this->info(sprintf('Replaying the playbook over %s to %s.',
            $settings->from->toDateString(), $settings->to->toDateString()));

        if ($settings->fundamentalsMode->isLookAhead()) {
            $this->warn('Fundamentals mode "latest" is LOOK-AHEAD CONTAMINATED. These numbers are not a forecast.');
        }

        $bar = $this->output->createProgressBar();
        $bar->setFormat(" %current% scrips  %elapsed:6s%  %message%\n");
        $bar->setMessage('');
        $bar->start();

        $result = $service->run($settings, function (string $symbol, int $plans) use ($bar): void {
            $bar->setMessage(sprintf('%s (%d plans)', $symbol, $plans));
            $bar->advance();
        });

        $bar->finish();
        $this->newLine(2);

        if ($this->option('json') === true) {
            $this->line((string) json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->render($result, $settings);

        return self::SUCCESS;
    }

    private function settings(): ?BacktestSettings
    {
        $profiles = [];

        foreach ((array) $this->option('profile') as $value) {
            $profile = InvestorProfile::tryFrom((string) $value);

            if ($profile === null) {
                $this->error(sprintf('Unknown profile "%s". Valid: %s.', $value, implode(', ', InvestorProfile::values())));

                return null;
            }

            $profiles[] = $profile;
        }

        $mode = FundamentalsMode::tryFrom((string) $this->option('fundamentals'));

        if ($mode === null) {
            $this->error('Unknown --fundamentals mode. Valid: none, dated, latest.');

            return null;
        }

        if ($mode->isLookAhead() && $this->option('allow-lookahead') !== true) {
            $this->error('--fundamentals=latest reads figures filed after the signal date and multiples struck at a '
                .'later price. It exists only to measure how much of a grade\'s edge is hindsight. Pass '
                .'--allow-lookahead if that is genuinely what you want.');

            return null;
        }

        $to = $this->option('to') !== null
            ? Carbon::parse((string) $this->option('to'))
            : $this->latestSession();

        if ($to === null) {
            $this->error('No price history is stored, so there is no window to back-test.');

            return null;
        }

        $from = $this->option('from') !== null
            ? Carbon::parse((string) $this->option('from'))
            : $to->copy()->subYears(3);

        if ($from->greaterThanOrEqualTo($to)) {
            $this->error('--from must be before --to.');

            return null;
        }

        return BacktestSettings::make(
            from: $from,
            to: $to,
            profiles: $profiles === [] ? InvestorProfile::cases() : $profiles,
            symbols: array_map('strval', (array) $this->option('symbol')),
            intervalSessions: $this->intOption('interval'),
            entryWindowSessions: $this->intOption('entry-window'),
            maxHoldSessions: $this->intOption('max-hold'),
            exitTarget: (string) $this->option('exit-target'),
            notionalPerTrade: $this->option('notional') === null ? null : (float) $this->option('notional'),
            fundamentalsMode: $mode,
            marketIndexSlug: $this->option('index') === null ? null : (string) $this->option('index'),
            stockLimit: $this->intOption('limit'),
        );
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return $value === null || $value === '' ? null : (int) $value;
    }

    private function latestSession(): ?Carbon
    {
        $latest = DB::table('stock_prices')->max('trade_date');

        return $latest === null ? null : Carbon::parse((string) $latest)->startOfDay();
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    private function render(BacktestResult $result, BacktestSettings $settings): void
    {
        $this->renderCoverage($result);
        $this->renderCosts($result);

        $this->newLine();
        $this->components->twoColumnDetail('<comment>Per grade, all profiles</comment>', '<comment>net of costs</comment>');
        $this->renderStatsTable($result->byGrade, $result->indexByGrade, 'Grade');

        $this->newLine();
        $this->components->twoColumnDetail('<comment>Per profile, all grades</comment>', '<comment>net of costs</comment>');
        $this->renderStatsTable($result->byProfile, [], 'Profile');

        foreach ($settings->profiles as $profile) {
            $this->newLine();
            $this->components->twoColumnDetail(
                sprintf('<comment>%s</comment>', $profile->label()),
                sprintf('<comment>hold %s, stop %.0f%%, targets +%.0f%%/+%.0f%%</comment>',
                    $profile->holdLabel(),
                    $profile->maxStopLoss() * 100,
                    $profile->targetBands()[0] * 100,
                    $profile->targetBands()[1] * 100,
                ),
            );
            $this->renderStatsTable(
                $result->byProfileGrade[$profile->value] ?? [],
                $result->indexByProfileGrade[$profile->value] ?? [],
                'Grade',
            );
        }

        $this->renderIndex($result);
    }

    private function renderCoverage(BacktestResult $result): void
    {
        $coverage = $result->coverage;

        $this->components->twoColumnDetail('<info>Coverage</info>', '<info>read this before any hit rate</info>');
        $this->components->twoColumnDetail('Window', sprintf('%s to %s', $coverage->from, $coverage->to));
        $this->components->twoColumnDetail('Sessions observed in window', (string) number_format($coverage->sessionsObserved));
        $this->components->twoColumnDetail('Session source', $coverage->sessionSource);
        $this->components->twoColumnDetail('Signal dates sampled', (string) number_format($coverage->signalDates));
        $this->components->twoColumnDetail('Latest stored bar (exit horizon)', $coverage->latestBarDate ?? 'n/a');
        $this->components->twoColumnDetail(
            'Suspected data gaps (blank day between sessions)',
            $coverage->suspectedCalendarGaps === []
                ? 'none'
                : sprintf('%d: %s', count($coverage->suspectedCalendarGaps),
                    implode(', ', array_slice($coverage->suspectedCalendarGaps, 0, 6))),
        );
        $this->components->twoColumnDetail('Scrips eligible / scored',
            sprintf('%s / %s', number_format($coverage->stocksEligible), number_format($coverage->stocksScored)));
        $this->components->twoColumnDetail('Excluded by instrument type', $this->describeCounts($coverage->excludedByInstrument));
        $this->components->twoColumnDetail('Bar coverage', $this->percent($coverage->barCoveragePercent()));
        $this->components->twoColumnDetail('Indicator coverage (of bars)', $this->percent($coverage->indicatorCoveragePercent()));
        $this->components->twoColumnDetail('Fundamentals', $coverage->fundamentalsMode);
        $this->components->twoColumnDetail('Fundamental coverage (of bars)', $this->percent($coverage->fundamentalCoveragePercent()));
        $this->components->twoColumnDetail('Verdicts emitted', (string) number_format($coverage->verdicts));
        $this->components->twoColumnDetail('Verdicts with no usable geometry', (string) number_format($coverage->verdictsWithoutGeometry));
        $this->components->twoColumnDetail('Plans simulated', (string) number_format($coverage->plansSimulated));
        $this->components->twoColumnDetail('Resolved / never filled / unresolved', sprintf(
            '%s / %s / %s',
            number_format($coverage->tradesResolved),
            number_format($coverage->tradesNoFill),
            number_format($coverage->tradesUnresolved),
        ));
        $this->components->twoColumnDetail('Resolution rate', $this->percent($coverage->resolutionPercent()));
        $this->components->twoColumnDetail('Index coverage', $this->percent($coverage->indexCoveragePercent));
        $this->components->twoColumnDetail('Index comparison complete', $coverage->indexSimulationComplete ? 'yes' : 'no');

        $this->newLine();
        $this->line('<comment>'.wordwrap($coverage->note(), 110).'</comment>');

        if ($coverage->skipReasons !== []) {
            $this->newLine();
            $this->line('<comment>Why (scrip, date, profile) combinations produced no plan:</comment>');
            $reasons = $coverage->skipReasons;
            arsort($reasons);

            foreach (array_slice($reasons, 0, 10, true) as $reason => $count) {
                $this->components->twoColumnDetail($reason, (string) number_format($count));
            }
        }
    }

    private function renderCosts(BacktestResult $result): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<info>Cost model</info>', '<info>charged on both sides</info>');

        $schedule = $result->costSchedule;

        foreach ((array) ($schedule['commission_tiers'] ?? []) as $tier) {
            $this->components->twoColumnDetail(
                $tier['up_to'] === null
                    ? 'Commission above the top tier'
                    : sprintf('Commission up to Rs %s', number_format((float) $tier['up_to'])),
                sprintf('%.4f%%', (float) $tier['rate_percent']),
            );
        }

        $this->components->twoColumnDetail('Minimum commission', sprintf('Rs %s', number_format((float) ($schedule['minimum_commission'] ?? 0), 2)));
        $this->components->twoColumnDetail('SEBON fee', sprintf('%.4f%%', (float) ($schedule['sebon_fee_percent'] ?? 0)));
        $this->components->twoColumnDetail('DP charge per side', sprintf('Rs %s', number_format((float) ($schedule['dp_charge_per_side'] ?? 0), 2)));
    }

    /**
     * @param  array<string, ?TradeStatistics>  $stats
     * @param  array<string, IndexComparison>  $index
     */
    private function renderStatsTable(array $stats, array $index, string $header): void
    {
        $rows = [];

        foreach ($stats as $key => $stat) {
            if ($stat === null) {
                $rows[] = [$this->rowLabel($key), 'n/a', 'n/a', 'n/a', 'n/a', 'n/a', 'n/a', 'n/a', 'n/a'];

                continue;
            }

            $comparison = $index[$key] ?? null;

            $rows[] = [
                $this->rowLabel($key),
                number_format($stat->resolved).' / '.number_format($stat->planned()),
                $this->percent($stat->hitRatePercent),
                $this->percent($stat->targetHitRatePercent),
                $this->signed($stat->averageGrossReturnPercent),
                $this->signed($stat->averageNetReturnPercent),
                $this->signed($stat->medianNetReturnPercent),
                $stat->averageHoldSessions === null ? 'n/a' : number_format($stat->averageHoldSessions, 1),
                $comparison === null ? '-' : $this->signed($comparison->averageExcessNetPercent),
            ];
        }

        $this->table(
            [$header, 'Resolved/plans', 'Hit rate', 'Target hit', 'Avg gross', 'Avg net', 'Median net', 'Avg hold', 'vs index (net)'],
            $rows,
        );
    }

    private function renderIndex(BacktestResult $result): void
    {
        $this->newLine();
        $overall = $result->indexOverall;

        $this->components->twoColumnDetail(
            sprintf('<info>Versus %s</info>', $overall->indexName ?? $overall->indexSlug),
            '<info>matched entry-to-exit windows</info>',
        );
        $this->components->twoColumnDetail('Index return over the whole window', $this->signed($overall->windowReturnPercent));
        $this->components->twoColumnDetail('Index coverage of the window', $this->percent($overall->coveragePercent));

        $rows = [];

        foreach ($result->indexByGrade as $grade => $comparison) {
            $rows[] = [
                $this->rowLabel($grade),
                number_format($comparison->matchedTrades),
                $this->signed($comparison->averageStrategyGrossReturnPercent),
                $this->signed($comparison->averageStrategyNetReturnPercent),
                $this->signed($comparison->averageIndexReturnPercent),
                $this->signed($comparison->averageExcessGrossPercent),
                $this->signed($comparison->averageExcessNetPercent),
                $this->percent($comparison->winVersusIndexPercent),
                $this->verdictWord($comparison),
            ];
        }

        $this->table(
            ['Grade', 'Matched', 'Strategy gross', 'Strategy net', 'Index same window', 'Excess gross', 'Excess net', 'Beat index', 'Verdict'],
            $rows,
        );

        $rupees = [];

        foreach ($result->indexByGrade as $grade => $comparison) {
            $rupees[] = [
                $this->rowLabel($grade),
                $comparison->strategyTerminalValue === null ? 'n/a' : number_format($comparison->strategyTerminalValue, 0),
                $comparison->indexBuyAndHoldValue === null ? 'n/a' : number_format($comparison->indexBuyAndHoldValue, 0),
                $comparison->terminalDifference === null ? 'n/a' : $this->signedRupees($comparison->terminalDifference),
            ];
        }

        $this->newLine();
        $this->line('<comment>Same capital, same entry dates, but bought index units and held to the window end:</comment>');
        $this->table(
            ['Grade', 'Strategy terminal (NPR)', 'Index instead (NPR)', 'Difference'],
            $rupees,
        );
    }

    private function verdictWord(IndexComparison $comparison): string
    {
        $beats = $comparison->beatsIndexNet();

        if ($beats === null) {
            return 'n/a';
        }

        return $beats ? '<info>beat</info>' : '<error>lost</error>';
    }

    private function rowLabel(string $key): string
    {
        return VerdictGrade::tryFrom($key)?->label()
            ?? InvestorProfile::tryFrom($key)?->shortLabel()
            ?? $key;
    }

    private function percent(?float $value): string
    {
        return $value === null ? 'n/a' : number_format($value, 2).'%';
    }

    private function signed(?float $value): string
    {
        return $value === null ? 'n/a' : sprintf('%+.2f%%', $value);
    }

    private function signedRupees(float $value): string
    {
        return sprintf('%s%s', $value >= 0 ? '+' : '-', number_format(abs($value), 0));
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function describeCounts(array $counts): string
    {
        if ($counts === []) {
            return 'none';
        }

        $parts = [];

        foreach ($counts as $key => $count) {
            $parts[] = sprintf('%s %s', number_format($count), $key);
        }

        return implode(', ', $parts);
    }
}
