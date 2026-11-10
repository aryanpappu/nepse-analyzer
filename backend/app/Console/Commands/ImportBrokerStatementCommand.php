<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CashTransaction;
use App\Models\Portfolio;
use App\Services\Import\BrokerStatementImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Import a broker account statement: the real trades and the real cash.
 *
 * Reviews by default. `--commit` is required to write, because this *replaces*
 * the portfolio's transactions and holdings — a MeroShare import leaves a
 * position snapshot (one synthetic buy per holding, no sells), and the point of
 * this command is to put the actual history in its place.
 */
class ImportBrokerStatementCommand extends Command
{
    protected $signature = 'nepse:broker-import
        {portfolio : Portfolio id to import into.}
        {file : Path to the broker account statement (.xlsx).}
        {--account=bank : Which cash account the movements post to (cash|bank).}
        {--commit : Actually write. Without this the command only reviews.}';

    protected $description = "Import a broker account statement's trades and cash movements, replacing a snapshot import";

    public function handle(BrokerStatementImporter $importer): int
    {
        $file = (string) $this->argument('file');

        if (! is_readable($file)) {
            $this->error("Cannot read [{$file}].");

            return self::FAILURE;
        }

        $account = (string) $this->option('account');

        if (! in_array($account, CashTransaction::accounts(), true)) {
            $this->error("Unknown cash account [{$account}].");

            return self::FAILURE;
        }

        try {
            $summary = $importer->preview($file);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->summarise($summary);

        if ($summary['reconciles'] !== true) {
            $this->error(
                'The rows do not replay to the statement\'s own closing balance, so the parser has misread '
                .'something. Refusing to go further — importing this would be worse than not importing it.'
            );

            return self::FAILURE;
        }

        if ($this->option('commit') !== true) {
            $this->newLine();
            $this->warn('Review only — nothing was written. Re-run with --commit to import.');
            $this->line('  <fg=yellow>--commit replaces this portfolio\'s transactions, holdings and cash rows.</>');

            return self::SUCCESS;
        }

        $portfolio = Portfolio::find((int) $this->argument('portfolio'));

        if ($portfolio === null) {
            $this->error('No such portfolio.');

            return self::FAILURE;
        }

        try {
            $result = $importer->import($portfolio, $file, $account);
        } catch (Throwable $e) {
            // The importer runs in one transaction and verifies the resulting
            // balance, so a failure here has changed nothing.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf(
            'Imported. Replaced %d snapshot transactions and %d cash rows with %d real trades and %d cash movements.',
            $result['replaced_transactions'],
            $result['replaced_cash_rows'],
            $result['trades_recorded'],
            $result['cash_rows_recorded'],
        ));
        $this->line(sprintf(
            '  Cash balance now <fg=green>NPR %s</>, matching the statement exactly.',
            number_format($result['final_cash_balance'], 2),
        ));

        $preserved = $result['preserved_symbols'] ?? [];

        if ($preserved !== []) {
            $this->line(sprintf(
                '  <fg=green>Kept %d holding(s) the statement says nothing about</> — IPO allotments, mutual funds',
                count($preserved),
            ));
            $this->line('  and bonus issues arrive through CDSC and appear in no broker record: '
                .implode(', ', $preserved));
        }

        if ($result['symbols_created'] !== []) {
            $this->line('  Created stubs for scrips not in the stock table: '
                .implode(', ', $result['symbols_created']).' — run <fg=cyan>nepse:classify</> to type them.');
        }

        $this->line('  Re-run <fg=cyan>nepse:indicators</> if you want the playbook rescored against the new positions.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function summarise(array $summary): void
    {
        $rows = [];

        foreach ($summary['totals'] as $kind => $total) {
            $rows[] = [
                str_replace('_', ' ', (string) $kind),
                (string) $total['rows'],
                number_format((float) $total['amount'], 2),
            ];
        }

        $this->table(['Movement', 'Rows', 'Amount (NPR)'], $rows);

        $this->line(sprintf(
            '  %d trades — %d buy, %d sell — across %d scrips.',
            $summary['trade_count'],
            $summary['buy_count'],
            $summary['sell_count'],
            count($summary['symbols']),
        ));

        $reconciles = $summary['reconciles'] === true;

        $this->line(sprintf(
            '  Statement closing balance <fg=cyan>NPR %s</>; the rows replay to <fg=cyan>NPR %s</> — %s',
            number_format((float) $summary['statement_closing_balance'], 2),
            number_format((float) $summary['replayed_closing_balance'], 2),
            $reconciles ? '<fg=green>reconciles</>' : '<fg=red>DOES NOT RECONCILE</>',
        ));

        if ($summary['worst_debit_exposure'] < 0.0) {
            $this->line(sprintf(
                '  Deepest debit balance <fg=yellow>NPR %s</> — the broker\'s T+2 credit line, recorded as it happened.',
                number_format($summary['worst_debit_exposure'], 2),
            ));
        }

        $parcels = $summary['opening_parcels'] ?? [];

        if ($parcels !== []) {
            $this->newLine();
            $this->line(sprintf(
                '  <fg=yellow>%d position(s) are sold here but never bought in this statement.</> These are shares that',
                count($parcels),
            ));
            $this->line('  reached the account outside the broker — an IPO allotment or a bonus issue — so they are');
            $this->line('  seeded at <options=bold>zero recorded cost</> with an <options=bold>unknown</> channel rather than being priced by guess:');

            foreach ($parcels as $parcel) {
                $this->line(sprintf(
                    '    %-8s %-6s units, first sold %s @ NPR %s',
                    $parcel['symbol'],
                    $parcel['quantity'],
                    $parcel['transacted_on'],
                    number_format((float) $parcel['sold_at'], 2),
                ));
            }

            $this->line('  Set each one\'s channel and cost to bring it into the gain figures.');
        }

        foreach ($summary['warnings'] as $warning) {
            $this->line('  <fg=yellow>note:</> '.$warning);
        }
    }
}
