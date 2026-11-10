<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Portfolio;
use App\Services\AI\BuyRecommendationService;
use App\Services\AI\LlmManager;
use App\Services\AI\PortfolioAnalyzer;
use Illuminate\Console\Command;
use Throwable;

class AnalyzePortfolioCommand extends Command
{
    protected $signature = 'nepse:portfolio-analyze
        {portfolio? : Portfolio id. Omit to run every portfolio.}
        {--recommend : Also generate buy recommendations for the idle cash.}';

    protected $description = 'Run the Claude portfolio health analysis, optionally with buy recommendations';

    public function handle(PortfolioAnalyzer $analyzer, BuyRecommendationService $recommender): int
    {
        $driver = (string) config('ai.driver');

        if (! app(LlmManager::class)->isConfigured($driver)) {
            $this->error(strtoupper($driver).'_API_KEY is not set in .env — analysis cannot run.');

            return self::FAILURE;
        }

        $portfolios = $this->argument('portfolio') !== null
            ? Portfolio::whereKey($this->argument('portfolio'))->get()
            : Portfolio::all();

        if ($portfolios->isEmpty()) {
            $this->warn('No portfolios found.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($portfolios as $portfolio) {
            $this->info("Analysing portfolio #{$portfolio->id} \"{$portfolio->name}\"...");

            try {
                $analysis = $analyzer->analyze($portfolio);

                $this->line("  Health score: <info>{$analysis->health_score}/100</info>"
                    ." | Diversification: {$analysis->diversification_grade}"
                    ." | Risk: {$analysis->risk_level}");

                if ($analysis->summary !== null) {
                    $this->line('  '.$analysis->summary);
                }

                foreach ((array) $analysis->rebalancing_actions as $action) {
                    $this->line(sprintf(
                        '   - [%s] %s %s: %s',
                        strtoupper((string) ($action['priority'] ?? 'medium')),
                        strtoupper((string) ($action['action'] ?? '')),
                        (string) ($action['symbol'] ?? ''),
                        (string) ($action['reason'] ?? ''),
                    ));
                }
            } catch (Throwable $e) {
                $failed++;
                $this->error('  '.$e->getMessage());
            }

            if ($this->option('recommend')) {
                $this->newLine();
                $this->info('  Generating buy recommendations...');

                try {
                    $run = $recommender->recommend($portfolio);

                    $this->line(sprintf(
                        '  Investable NPR %s | allocated NPR %s | reserved NPR %s',
                        number_format((float) $run->investable_cash, 2),
                        number_format((float) $run->allocated_amount, 2),
                        number_format((float) $run->reserve_amount, 2),
                    ));

                    $rows = [];

                    foreach ($run->recommendations as $recommendation) {
                        $rows[] = [
                            $recommendation->rank,
                            $recommendation->stock->symbol,
                            number_format((float) $recommendation->suggested_quantity),
                            number_format((float) $recommendation->suggested_price, 2),
                            number_format((float) $recommendation->estimated_cost, 2),
                            $recommendation->conviction.'%',
                        ];
                    }

                    if ($rows !== []) {
                        $this->table(['#', 'Symbol', 'Qty', 'Price', 'Cost (NPR)', 'Conviction'], $rows);
                    }
                } catch (Throwable $e) {
                    $failed++;
                    $this->error('  '.$e->getMessage());
                }
            }

            $this->newLine();
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
