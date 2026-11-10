<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CashStatementImport;
use App\Models\Portfolio;
use App\Services\Import\CashStatementImportService;
use App\Services\Import\CashStatementParser;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Imports a broker cash statement without the UI.
 *
 * Reviews by default and commits only when told to, so the destructive step is
 * never the one you get by forgetting a flag. Nothing here is networked.
 */
class ImportCashStatementCommand extends Command
{
    protected $signature = 'nepse:cash-import
        {portfolio : Portfolio id to import into.}
        {file : Path to the statement (TMS deposit/withdraw log, or broker account statement).}
        {--format= : Force a format (tms_deposit_log|broker_ledger). Auto-detected by default.}
        {--account= : Which cash account to post to (cash|bank). Defaults to config.}
        {--opening-balance= : Balance carried into this statement from an earlier one.}
        {--commit : Actually write the movements. Without this the command only reviews.}';

    protected $description = 'Import a broker cash statement into the cash ledger, with a review step';

    public function handle(CashStatementImportService $imports): int
    {
        $portfolio = Portfolio::find($this->argument('portfolio'));

        if ($portfolio === null) {
            $this->error("No portfolio with id {$this->argument('portfolio')}.");

            return self::FAILURE;
        }

        $path = (string) $this->argument('file');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        try {
            $import = $imports->startImport(
                $portfolio,
                (string) file_get_contents($path),
                basename($path),
                $this->option('format') === null ? null : (string) $this->option('format'),
                $this->option('account') === null ? null : (string) $this->option('account'),
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->review($imports, $import);

        if (! $this->option('commit')) {
            $this->newLine();
            $this->comment('Review only — nothing was written. Re-run with --commit to import.');

            return self::SUCCESS;
        }

        try {
            $import = $imports->commit(
                $import,
                $this->option('opening-balance') === null
                    ? null
                    : (float) $this->option('opening-balance'),
            );
        } catch (RuntimeException $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Committed {$import->committed_count} movements to the {$import->account} account.");
        $this->reconciliation($import);

        return self::SUCCESS;
    }

    private function review(CashStatementImportService $imports, CashStatementImport $import): void
    {
        $summary = $imports->summary($import);

        $this->info(sprintf(
            'Portfolio #%d — %s, %d rows parsed as %s.',
            $import->portfolio_id,
            $import->filename,
            $import->parsed_count,
            $import->format,
        ));
        $this->newLine();

        $this->table(
            ['Date', 'Dir', 'Amount', 'Channel', 'Status', 'Why'],
            array_map(static fn (array $row): array => [
                (string) ($row['occurred_on'] ?? '—'),
                match ($row['direction'] ?? null) {
                    CashStatementParser::DIRECTION_OUT => 'out',
                    CashStatementParser::DIRECTION_IN => 'in',
                    default => '?',
                },
                $row['amount'] === null ? '—' : number_format((float) $row['amount'], 2),
                (string) ($row['channel'] ?? '—'),
                (string) ($row['status'] ?? '—'),
                // Truncated: the full reason is in the API payload, and a
                // wrapped table is unreadable.
                mb_strimwidth((string) ($row['reason'] ?? ''), 0, 60, '…'),
            ], $import->merged_rows ?? []),
        );

        foreach ($import->warnings ?? [] as $warning) {
            $this->warn('! '.$warning);
        }

        $this->newLine();
        $this->line(sprintf(
            '  ready %d · excluded (failed) %d · duplicate %d · needs review %d · overlap %d',
            $summary['ready'],
            $summary['excluded_failed'],
            $summary['duplicate'],
            $summary['needs_review'],
            $summary['overlap_warning'],
        ));
        $this->line(sprintf(
            '  money in  NPR %s (%d)   money out  NPR %s (%d)   net  NPR %s',
            number_format((float) $summary['money_in'], 2),
            $summary['deposits'],
            number_format((float) $summary['money_out'], 2),
            $summary['withdrawals'],
            number_format((float) $summary['net_injected'], 2),
        ));
    }

    private function reconciliation(CashStatementImport $import): void
    {
        $reconciliation = $import->reconciliation ?? [];

        if ($reconciliation === []) {
            return;
        }

        $this->newLine();
        $this->info('Reconciliation');

        $this->table(['', ''], [
            ['Money in', number_format((float) $reconciliation['money_in'], 2).' ('.$reconciliation['deposits'].' deposits)'],
            ['Money out', number_format((float) $reconciliation['money_out'], 2).' ('.$reconciliation['withdrawals'].' withdrawals)'],
            ['Net injected', number_format((float) $reconciliation['net_injected'], 2)],
            ['Trades imply', $this->money($reconciliation['ledger_implied_capital'])],
            ['Difference', $this->money($reconciliation['difference'])],
            ['Holdings + cash', $this->money($reconciliation['closing_net_worth'])],
            ['Profit', $this->money($reconciliation['profit'])],
            ['Simple return', $reconciliation['simple_return_percent'] === null
                ? 'n/a'
                : number_format((float) $reconciliation['simple_return_percent'], 2).'%'],
        ]);

        $this->line('  '.$reconciliation['note']);
    }

    private function money(mixed $value): string
    {
        // A subsystem that cannot answer says so rather than printing 0.00.
        return $value === null ? 'n/a' : number_format((float) $value, 2);
    }
}
