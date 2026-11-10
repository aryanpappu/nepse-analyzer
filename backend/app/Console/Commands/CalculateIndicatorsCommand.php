<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Stock;
use App\Services\Indicators\IndicatorPersistenceService;
use App\Services\Playbook\PlaybookService;
use Illuminate\Console\Command;
use Throwable;

class CalculateIndicatorsCommand extends Command
{
    protected $signature = 'nepse:indicators
        {--symbol= : Only recalculate this ticker.}
        {--days= : Only rewrite the last N sessions (default: all available).}
        {--full : Rewrite the entire history for every stock.}';

    protected $description = 'Recalculate RSI, MACD, moving averages and other technical indicators';

    public function handle(IndicatorPersistenceService $indicators): int
    {
        $query = Stock::query()->active();

        if ($symbol = $this->option('symbol')) {
            $query->symbol($symbol);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->warn('No stocks matched. Run `php artisan nepse:scrape` first.');

            return self::SUCCESS;
        }

        $days = $this->option('full')
            ? null
            : ($this->option('days') !== null ? (int) $this->option('days') : null);

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $written = 0;
        $skipped = 0;
        $failed = 0;

        $query->chunkById(100, function ($stocks) use ($indicators, $days, $bar, &$written, &$skipped, &$failed): void {
            foreach ($stocks as $stock) {
                try {
                    $rows = $indicators->recalculate($stock, $days);
                    $rows > 0 ? $written += $rows : $skipped++;
                } catch (Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->error("  {$stock->symbol}: {$e->getMessage()}");
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("Indicator rows written: {$written}");
        $this->line("Stocks skipped for insufficient history: {$skipped}");

        // Profile verdicts are a pure function of these indicators, and scoring
        // the whole market takes under a second, so it runs here rather than
        // being a separate step someone has to remember. A single-symbol run is
        // left alone: rescoring the market off one scrip's indicators would be
        // surprising, and `nepse:playbook` covers that case explicitly.
        if ($this->option('symbol') === null) {
            $playbook = app(PlaybookService::class)->rebuild();
            $this->line(sprintf('Profile verdicts written: %s across %s scrips.',
                number_format($playbook['written']), number_format($playbook['scored'])));
        }

        if ($failed > 0) {
            $this->warn("Stocks that failed: {$failed}");
        }

        return self::SUCCESS;
    }
}
