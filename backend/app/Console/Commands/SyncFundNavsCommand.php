<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FundNav;
use App\Models\Stock;
use App\Services\Scraper\FundNavService;
use Illuminate\Console\Command;

class SyncFundNavsCommand extends Command
{
    protected $signature = 'nepse:navs
        {--symbol=* : Only sync these fund tickers (repeatable).}
        {--show : Print the latest NAV and premium/discount for each fund.}';

    protected $description = 'Sync daily NAV history for closed-end mutual funds';

    public function handle(FundNavService $navs): int
    {
        $symbols = (array) $this->option('symbol');
        $symbols = $symbols === [] ? null : $symbols;

        $expected = $symbols !== null
            ? count($symbols)
            : Stock::query()->active()->where('instrument_type', 'mutual_fund')->count();

        if ($expected === 0) {
            $this->warn('No mutual funds found. Run `php artisan nepse:scrape` first.');

            return self::SUCCESS;
        }

        $this->info("Syncing NAV history for {$expected} funds...");
        $bar = $this->output->createProgressBar($expected);
        $bar->start();

        $run = $navs->sync($symbols, function () use ($bar): void {
            $bar->advance();
        });

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Status', 'Funds', 'NAV points added', 'Updated', 'No data', 'Duration'],
            [[
                $run->status,
                $run->rows_found,
                number_format($run->rows_created),
                number_format($run->rows_updated),
                $run->rows_rejected,
                round(($run->duration_ms ?? 0) / 1000, 1).' s',
            ]],
        );

        if ($this->option('show')) {
            $this->showLatest($symbols);
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>|null  $symbols
     */
    private function showLatest(?array $symbols): void
    {
        $query = Stock::query()->active();

        $symbols !== null
            ? $query->whereIn('symbol', array_map('strtoupper', $symbols))
            : $query->where('instrument_type', 'mutual_fund');

        $rows = [];

        foreach ($query->orderBy('symbol')->get() as $fund) {
            $nav = FundNav::where('stock_id', $fund->id)->orderByDesc('nav_date')->first();

            if ($nav === null) {
                continue;
            }

            $rows[] = [
                $fund->symbol,
                $nav->nav_date->toDateString(),
                number_format($nav->nav, 2),
                $fund->last_price === null ? '-' : number_format((float) $fund->last_price, 2),
                $nav->premium_discount_percent === null
                    ? '-'
                    : sprintf('%+.2f%%', $nav->premium_discount_percent),
                $nav->discountLabel() ?? '-',
            ];
        }

        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->table(['Fund', 'NAV date', 'NAV', 'Market', 'Premium/Discount', 'Read'], $rows);
    }
}
