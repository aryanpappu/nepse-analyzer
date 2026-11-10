<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Scraper\FundamentalPeriodBackfiller;
use App\Services\Scraper\FundamentalPeriodBackfillReport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class DateFundamentalPeriodsCommand extends Command
{
    protected $signature = 'nepse:date-fundamentals
        {--dry-run : Report what would be dated without writing anything.}
        {--force : Re-derive rows that already carry a reported_on.}
        {--observed-through= : Treat this date as the last observed session (YYYY-MM-DD). Defaults to the newest stored trade date.}
        {--formats : Print the per-fiscal_period breakdown.}';

    protected $description = 'Derive stock_fundamentals.reported_on from the stored Bikram Sambat fiscal_period';

    public function handle(FundamentalPeriodBackfiller $backfiller): int
    {
        $observedThrough = $this->option('observed-through') !== null
            ? Carbon::parse((string) $this->option('observed-through'))->startOfDay()
            : null;

        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun
            ? 'Measuring which fiscal periods can be dated. Nothing will be written.'
            : 'Deriving reporting dates from the stored fiscal periods.');

        $report = $backfiller->backfill($dryRun, (bool) $this->option('force'), $observedThrough);

        $this->newLine();
        $this->table(
            ['Examined', 'Dated', 'Exact', 'Approximate', 'Undated', 'Written', 'Observed through'],
            [[
                number_format($report->examined),
                sprintf('%s (%.2f%%)', number_format($report->dated), $report->datedPercent()),
                number_format($report->exact),
                number_format($report->approximate),
                number_format($report->undated),
                $report->dryRun ? 'dry run' : number_format($report->written),
                $report->observedThrough ?? 'no stored prices — no future-period check made',
            ]],
        );

        if ($report->approximate > 0) {
            $this->comment(sprintf(
                '%s dates are approximate: the first three Bikram Sambat quarter ends fall on BS month '
                .'boundaries no formula gives, so they are interpolated between two known fiscal-year ends '
                .'and land within a few days (three at the widest measured). Stored as reported_on_precision = approximate. '
                .'Assert a real date in config(nepse.fiscal_quarter_ends) to make one exact.',
                number_format($report->approximate),
            ));
        }

        if ($report->undated > 0) {
            $this->newLine();
            $this->warn(sprintf('%s rows keep a NULL reported_on:', number_format($report->undated)));

            foreach ($report->undatedReasons as $reason => $count) {
                $this->line(sprintf('  - %6s  %s', number_format($count), $reason));
            }
        }

        if ($this->option('formats')) {
            $this->printFormats($report);
        }

        if (! $report->dryRun && $report->scrapeRunId !== null) {
            $this->newLine();
            $this->comment("Recorded as scrape run #{$report->scrapeRunId}.");
        }

        return self::SUCCESS;
    }

    private function printFormats(FundamentalPeriodBackfillReport $report): void
    {
        $rows = [];

        foreach ($report->byPeriod as $period => $counts) {
            $rows[] = [
                $period,
                number_format($counts['total']),
                $counts['exact'] === 0 ? '-' : number_format($counts['exact']),
                $counts['approximate'] === 0 ? '-' : number_format($counts['approximate']),
                $counts['undated'] === 0 ? '-' : number_format($counts['undated']),
                $counts['reason'] ?? '',
            ];
        }

        $this->newLine();
        $this->table(['fiscal_period', 'Rows', 'Exact', 'Approx', 'Undated', 'Why undated'], $rows);
    }
}
