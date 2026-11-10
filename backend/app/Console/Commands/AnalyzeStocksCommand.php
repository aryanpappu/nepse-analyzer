<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\ClaudeApiException;
use App\Jobs\QueueStockAnalysisJob;
use App\Models\Stock;
use App\Services\AI\LlmClient;
use App\Services\AI\LlmManager;
use App\Services\AI\StockSignalAnalyzer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class AnalyzeStocksCommand extends Command
{
    protected $signature = 'nepse:analyze
        {--symbol=* : Analyse only these tickers (repeatable).}
        {--date= : Analysis date (YYYY-MM-DD). Defaults to today.}
        {--limit= : Cap how many stocks are analysed.}
        {--queue : Dispatch through the queue instead of running inline.}';

    protected $description = 'Generate Claude buy/sell/hold signals for NEPSE stocks';

    public function handle(StockSignalAnalyzer $analyzer, LlmClient $claude): int
    {
        $driver = (string) config('ai.driver');

        if (! app(LlmManager::class)->isConfigured($driver)) {
            $this->error(strtoupper($driver).'_API_KEY is not set in .env — analysis cannot run.');

            return self::FAILURE;
        }

        $date = $this->option('date') !== null ? Carbon::parse($this->option('date')) : Carbon::today();
        $symbols = (array) $this->option('symbol');

        if ($this->option('queue')) {
            QueueStockAnalysisJob::dispatch(
                $date->toDateString(),
                $this->option('limit') !== null ? (int) $this->option('limit') : null,
            );
            $this->info('Analysis dispatched to the queue.');

            return self::SUCCESS;
        }

        $query = Stock::query()->active()->where('instrument_type', 'equity');

        if ($symbols !== []) {
            $query->whereIn('symbol', array_map('strtoupper', $symbols));
        } else {
            $minTurnover = (float) config('nepse.analysis.min_turnover');
            $minHistory = (int) config('nepse.analysis.min_history_days');

            $query->has('prices', '>=', $minHistory)
                ->whereHas('prices', function ($q) use ($minTurnover): void {
                    $q->where('trade_date', '>=', Carbon::today()->subDays(7)->toDateString())
                        ->where('turnover', '>=', $minTurnover);
                })
                ->orderByDesc('market_capitalization');
        }

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $stocks = $query->get();

        if ($stocks->isEmpty()) {
            $this->warn('No stocks qualified for analysis.');

            return self::SUCCESS;
        }

        $budget = (int) config($driver.'.daily_call_limit');
        $used = $claude->callsUsedToday();
        $model = app(LlmManager::class)->models($driver)['default'];

        $this->info("Analysing {$stocks->count()} stocks via {$driver} ({$model}). API calls used today: {$used}"
            .($budget > 0 ? " of {$budget}" : ' (no limit)'));
        $this->newLine();

        $succeeded = 0;
        $failed = 0;
        $rows = [];

        foreach ($stocks as $stock) {
            try {
                $signal = $analyzer->analyze($stock, $date);
                $succeeded++;

                $rows[] = [
                    $stock->symbol,
                    strtoupper($signal->signal->value),
                    $signal->confidence.'%',
                    $signal->entry_price ?? '-',
                    $signal->stop_loss ?? '-',
                    $signal->target_1 ?? '-',
                    $signal->risk_level->value,
                ];

                $this->line("  <info>✓</info> {$stock->symbol}: "
                    .strtoupper($signal->signal->value)." ({$signal->confidence}%)");
            } catch (ClaudeApiException $e) {
                $failed++;
                $this->line("  <fg=red>✗</> {$stock->symbol}: {$e->getMessage()}");

                // The spend guard stopping us is a hard stop, not a per-stock
                // failure — continuing would just repeat the same error.
                if ($e->isBudgetExhausted()) {
                    $this->newLine();
                    $this->error('Daily API budget reached; stopping.');
                    break;
                }
            } catch (Throwable $e) {
                $failed++;
                $this->line("  <fg=red>✗</> {$stock->symbol}: {$e->getMessage()}");
            }
        }

        $this->newLine();

        if ($rows !== []) {
            $this->table(['Symbol', 'Signal', 'Confidence', 'Entry', 'Stop', 'Target', 'Risk'], $rows);
        }

        $this->info("Analysed: {$succeeded}, failed: {$failed}");

        return $failed > 0 && $succeeded === 0 ? self::FAILURE : self::SUCCESS;
    }
}
