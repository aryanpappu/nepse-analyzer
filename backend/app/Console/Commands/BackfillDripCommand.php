<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketCalendarDay;
use App\Services\Scraper\Backfill\HistoryBackfillService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class BackfillDripCommand extends Command
{
    protected $signature = 'nepse:backfill-drip
        {--force : Run one drip cycle right now, inline, instead of waiting for the schedule.}
        {--reset : Reset progress back to pending so it is picked up again.}
        {--symbol= : Scope --reset (or the default status view) to one ticker.}
        {--mark-holiday= : Manually classify a date (YYYY-MM-DD) as a market holiday. Always wins over inference.}
        {--mark-trading-day= : Manually classify a date (YYYY-MM-DD) as a trading day. Always wins over inference.}';

    protected $description = 'Inspect or manually drive the self-healing historical backfill';

    public function handle(HistoryBackfillService $backfill): int
    {
        $symbol = $this->option('symbol');

        if ($this->option('mark-holiday') !== null || $this->option('mark-trading-day') !== null) {
            return $this->handleManualMark($backfill);
        }

        if ($this->option('reset')) {
            $count = $backfill->reset($symbol);
            $this->info("Reset {$count} scrip(s) back to pending.");

            return self::SUCCESS;
        }

        if ($this->option('force')) {
            $this->info('Running one drip cycle inline (this hits the network)...');
            $result = $backfill->runDrip();

            $this->table(array_keys($result), [array_values($result)]);

            return self::SUCCESS;
        }

        return $this->showStatus($symbol, $backfill);
    }

    private function handleManualMark(HistoryBackfillService $backfill): int
    {
        $holidayDate = $this->option('mark-holiday');
        $tradingDayDate = $this->option('mark-trading-day');

        if ($holidayDate !== null && $tradingDayDate !== null) {
            $this->error('Pass only one of --mark-holiday or --mark-trading-day at a time.');

            return self::FAILURE;
        }

        [$date, $classification] = $holidayDate !== null
            ? [$holidayDate, MarketCalendarDay::HOLIDAY]
            : [$tradingDayDate, MarketCalendarDay::TRADING_DAY];

        try {
            $backfill->markManualCalendarDay((string) $date, $classification);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$date} manually classified as '{$classification}'. This always wins over automatic inference.");

        return self::SUCCESS;
    }

    private function showStatus(?string $symbol, HistoryBackfillService $backfill): int
    {
        $summary = $backfill->statusSummary($symbol);

        if ($summary['rows']->isEmpty()) {
            $this->warn('No backfill progress rows yet. Run with --force once, or wait for the schedule.');

            return self::SUCCESS;
        }

        $this->table(
            ['Symbol', 'Status', 'Earliest bar', 'Target', 'Attempts', 'Last attempt', 'Last result'],
            $summary['rows']->map(static fn (object $row): array => [
                $row->symbol,
                $row->status,
                $row->earliest_bar_date,
                $row->target_start_date,
                $row->attempts,
                $row->last_attempted_at,
                $row->last_result,
            ])->all(),
        );

        $this->newLine();
        $this->comment(sprintf(
            'pending=%d active=%d complete=%d exhausted=%d | estimated runs to first-touch everything still pending=%d',
            $summary['counts']['pending'] ?? 0,
            $summary['counts']['active'] ?? 0,
            $summary['counts']['complete'] ?? 0,
            $summary['counts']['exhausted'] ?? 0,
            $summary['estimated_runs_to_clear'],
        ));

        $calendar = $summary['calendar_counts'];
        $this->comment(sprintf(
            'calendar: trading_day=%d holiday=%d unknown=%d (dates only recorded once resolved)',
            $calendar['trading_day'] ?? 0,
            $calendar['holiday'] ?? 0,
            $calendar['unknown'] ?? 0,
        ));

        return self::SUCCESS;
    }
}
