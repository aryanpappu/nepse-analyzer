<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Stock;
use App\Services\Scraper\NepseScraperService;
use Illuminate\Console\Command;

class BackfillHistoryCommand extends Command
{
    protected $signature = 'nepse:backfill
        {--symbol=* : Only backfill these tickers (repeatable).}
        {--days=400 : How many trading sessions to pull per scrip.}
        {--limit= : Cap how many scrips are processed this run.}
        {--indicators : Recalculate technical indicators afterwards.}';

    protected $description = 'Backfill historical daily OHLCV bars so charts and indicators work immediately';

    public function handle(NepseScraperService $scraper): int
    {
        $symbols = (array) $this->option('symbol');
        $symbols = $symbols === [] ? null : $symbols;
        $days = max(30, (int) $this->option('days'));
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $expected = $symbols !== null
            ? count($symbols)
            : min($limit ?? PHP_INT_MAX, Stock::query()->active()
                ->whereIn('instrument_type', ['equity', 'promoter', 'mutual_fund'])
                ->count());

        if ($expected === 0) {
            $this->warn('No stocks to backfill. Run `php artisan nepse:scrape` first.');

            return self::SUCCESS;
        }

        $this->info("Backfilling up to {$days} sessions for {$expected} scrips.");
        $this->comment('This is rate limited to stay polite to the source, so expect a few minutes.');
        $this->newLine();

        $bar = $this->output->createProgressBar($expected);
        $bar->start();

        $run = $scraper->backfillHistory(
            $symbols,
            $days,
            $limit,
            function (string $symbol, int $written) use ($bar): void {
                $bar->setMessage($symbol);
                $bar->advance();
            },
        );

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Status', 'Scrips', 'Bars created', 'Bars updated', 'No data', 'Duration'],
            [[
                $run->status,
                $run->rows_found,
                number_format($run->rows_created),
                number_format($run->rows_updated),
                $run->rows_rejected,
                round(($run->duration_ms ?? 0) / 1000, 1).' s',
            ]],
        );

        if ($run->rows_rejected > 0) {
            $this->comment("{$run->rows_rejected} scrips had no chart history — usually debentures or "
                .'newly listed companies. That is expected.');
        }

        if ($this->option('indicators')) {
            $this->newLine();
            $this->info('Recalculating technical indicators...');
            $this->call('nepse:indicators', ['--full' => true]);
        } else {
            $this->newLine();
            $this->comment('Next: php artisan nepse:indicators --full');
        }

        return self::SUCCESS;
    }
}
