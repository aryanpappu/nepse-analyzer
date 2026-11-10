<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CashPlacement;
use App\Models\Portfolio;
use App\Services\Portfolio\CashPlacementService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Lists cash placements (fixed deposits, etc.) with interest accrued to date.
 * Pure DB reads through CashPlacementService — no network call, safe to run
 * any time.
 */
class ListCashPlacementsCommand extends Command
{
    protected $signature = 'nepse:cash-placements
        {portfolio? : Portfolio id. Omit to run every portfolio.}
        {--as-of= : Date to accrue interest to (Y-m-d). Defaults to today.}
        {--status= : Filter by status (active|matured|withdrawn|broken_early).}
        {--maturing-soon : Show only placements maturing within the configured window.}';

    protected $description = 'List cash placements (fixed deposits, etc.) with interest accrued to date';

    public function handle(CashPlacementService $service): int
    {
        $asOf = $this->option('as-of') !== null
            ? Carbon::parse((string) $this->option('as-of'))
            : Carbon::today();
        $status = $this->option('status');

        $portfolios = $this->argument('portfolio') !== null
            ? Portfolio::whereKey($this->argument('portfolio'))->get()
            : Portfolio::all();

        if ($portfolios->isEmpty()) {
            $this->warn('No portfolios found.');

            return self::SUCCESS;
        }

        foreach ($portfolios as $portfolio) {
            $this->reportPortfolio($service, $portfolio, $asOf, $status);
        }

        return self::SUCCESS;
    }

    private function reportPortfolio(
        CashPlacementService $service,
        Portfolio $portfolio,
        Carbon $asOf,
        ?string $status,
    ): void {
        $placements = $service->forPortfolio($portfolio);

        if ($status !== null) {
            $placements = $placements->filter(
                static fn (CashPlacement $p): bool => $p->status->value === $status
            )->values();
        }

        $this->info("Portfolio #{$portfolio->id} \"{$portfolio->name}\" — as of {$asOf->toDateString()}");

        if ($placements->isEmpty()) {
            $this->line('  No cash placements.');
            $this->newLine();

            return;
        }

        $summary = $service->summarize($placements, $asOf);
        $rows = [];

        foreach ($placements as $placement) {
            $accrual = $service->accrualFor($placement, $asOf);
            $maturesSoon = $placement->maturity_date !== null
                && $placement->maturity_date->gt($asOf)
                && $placement->maturity_date->lte($asOf->copy()->addDays($summary['maturing_soon_within_days']));

            if ($this->option('maturing-soon') && ! $maturesSoon) {
                continue;
            }

            $rows[] = [
                $placement->id,
                $placement->kind->value,
                $placement->institution ?? '-',
                number_format((float) $placement->principal, 2),
                number_format((float) $placement->interest_rate, 3).'%',
                $placement->compounding_basis->value,
                $placement->payout->value,
                $placement->start_date->toDateString(),
                $placement->maturity_date?->toDateString() ?? '-',
                $placement->status->value,
                number_format($accrual->accruedInterest, 2),
                $maturesSoon ? 'yes' : '',
            ];
        }

        if ($rows === []) {
            $this->line('  No matching placements.');
        } else {
            $this->table(
                ['ID', 'Kind', 'Institution', 'Principal', 'Rate', 'Basis', 'Payout', 'Start', 'Maturity', 'Status', 'Accrued', 'Soon?'],
                $rows,
            );
        }

        $this->line(sprintf(
            '  Total principal: NPR %s | Total accrued: NPR %s | Weighted avg rate: %s | Matured: %d | Maturing soon (%d d): %d',
            number_format($summary['total_principal'], 2),
            number_format($summary['total_accrued_interest'], 2),
            $summary['weighted_average_rate'] !== null ? number_format($summary['weighted_average_rate'], 3).'%' : 'n/a',
            $summary['matured_count'],
            $summary['maturing_soon_within_days'],
            $summary['maturing_soon_count'],
        ));
        $this->newLine();
    }
}
