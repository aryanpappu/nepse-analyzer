<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Stock;
use App\Services\Scraper\InstrumentClassifier;
use App\Services\Scraper\Sources\SharesansarNavSource;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ClassifyInstrumentsCommand extends Command
{
    protected $signature = 'nepse:classify
        {--probe-nav : Confirm fund candidates by checking whether they publish a NAV. Definitive but slower.}
        {--set= : Manually set a type. Use with --symbol. Marks it as a manual override.}
        {--symbol=* : Limit to these tickers (repeatable).}
        {--review : List only the scrips that need manual review.}
        {--dry-run : Show what would change without writing.}';

    protected $description = 'Classify scrips as equity, mutual fund, debenture, promoter, preference — or flag for review';

    public function handle(InstrumentClassifier $classifier, SharesansarNavSource $navSource): int
    {
        $symbols = array_map('strtoupper', (array) $this->option('symbol'));

        // Manual override path: an explicit human decision.
        if ($this->option('set') !== null) {
            return $this->applyManual($symbols);
        }

        if ($this->option('review')) {
            return $this->listForReview();
        }

        $query = Stock::with('sector')->orderBy('symbol');

        if ($symbols !== []) {
            $query->whereIn('symbol', $symbols);
        }

        $stocks = $query->get();

        if ($stocks->isEmpty()) {
            $this->warn('No stocks found. Run `php artisan nepse:scrape` first.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('probe-nav')) {
            $this->probeNav($stocks, $classifier, $navSource, $dryRun);
        }

        $changes = [];
        $counts = [];
        $review = [];

        foreach ($stocks as $stock) {
            $verdict = $classifier->classify($stock);
            $counts[$verdict['type']] = ($counts[$verdict['type']] ?? 0) + 1;

            if ($verdict['type'] === InstrumentClassifier::UNKNOWN) {
                $review[] = [$stock->symbol, $stock->name, $verdict['reason']];
            }

            $changed = $verdict['type'] !== $stock->instrument_type
                || $verdict['source'] !== $stock->classification_source;

            if (! $changed) {
                continue;
            }

            if ($verdict['type'] !== $stock->instrument_type) {
                $changes[] = [$stock->symbol, $stock->name, $stock->instrument_type, $verdict['type'], $verdict['source']];
            }

            if (! $dryRun && $verdict['source'] !== InstrumentClassifier::SOURCE_MANUAL) {
                $stock->update([
                    'instrument_type' => $verdict['type'],
                    'classification_source' => $verdict['source'],
                ]);
            }
        }

        if ($changes !== []) {
            $this->table(
                ['Symbol', 'Name', 'Was', 'Now', 'Decided by'],
                array_slice($changes, 0, 30),
            );

            if (count($changes) > 30) {
                $this->line('  ... and '.(count($changes) - 30).' more.');
            }
        }

        $this->newLine();
        $summary = [];

        foreach ($counts as $type => $count) {
            $summary[] = [$type, $count];
        }

        $this->table(['Instrument type', 'Count'], $summary);

        $this->info($dryRun
            ? count($changes).' scrips would be reclassified (dry run, nothing written).'
            : count($changes).' scrips reclassified.');

        if ($review !== []) {
            $this->newLine();
            $this->warn(count($review).' scrips could not be classified and are marked "unknown". '
                .'They are excluded from AI analysis until set manually:');

            foreach (array_slice($review, 0, 15) as [$symbol, $name, $reason]) {
                $this->line("  - {$symbol} ({$name})");
            }

            $this->newLine();
            $this->line('  Set one manually with:');
            $this->line('    php artisan nepse:classify --symbol=XYZ --set=equity');
        }

        if (! $this->option('probe-nav')) {
            $this->newLine();
            $this->comment('Tip: --probe-nav confirms funds by checking for a published NAV.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Stock>  $stocks
     */
    private function probeNav(
        $stocks,
        InstrumentClassifier $classifier,
        SharesansarNavSource $navSource,
        bool $dryRun,
    ): void {
        // Only probe plausible fund candidates. Probing all 343 would be 343
        // requests to answer a question the name already settles for most.
        $candidates = $stocks->filter(function (Stock $stock) use ($classifier): bool {
            if ($stock->classification_source === InstrumentClassifier::SOURCE_MANUAL) {
                return false;
            }

            return in_array(
                $classifier->classify($stock)['type'],
                [InstrumentClassifier::MUTUAL_FUND, InstrumentClassifier::UNKNOWN],
                true,
            );
        });

        if ($candidates->isEmpty()) {
            return;
        }

        $this->info("Probing NAV for {$candidates->count()} candidates...");
        $bar = $this->output->createProgressBar($candidates->count());
        $bar->start();

        $companyIds = $navSource->resolveCompanyIds();
        $withNav = 0;

        foreach ($candidates as $stock) {
            $id = $stock->external_id ?? ($companyIds[$stock->symbol] ?? null);
            $hasNav = $id !== null && $navSource->fetchNavHistory($stock->symbol, $id) !== [];

            if ($hasNav) {
                $withNav++;
            }

            if (! $dryRun) {
                $stock->update([
                    'external_id' => $id ?? $stock->external_id,
                    'publishes_nav' => $hasNav,
                    'nav_checked_at' => Carbon::now(),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->line("  {$withNav} of {$candidates->count()} publish a NAV.");
        $this->comment('  A fund that has matured stops publishing NAV, so "no NAV" is not proof '
            .'of being an equity — those keep whatever the name and sector say.');
        $this->newLine();

        // Refresh so the classification pass sees the probe results.
        $stocks->each->refresh();
    }

    /**
     * @param  list<string>  $symbols
     */
    private function applyManual(array $symbols): int
    {
        $type = strtolower((string) $this->option('set'));

        if (! in_array($type, InstrumentClassifier::types(), true)) {
            $this->error("Unknown instrument type [{$type}]. Valid: "
                .implode(', ', InstrumentClassifier::types()));

            return self::FAILURE;
        }

        if ($symbols === []) {
            $this->error('--set requires at least one --symbol.');

            return self::FAILURE;
        }

        $stocks = Stock::whereIn('symbol', $symbols)->get();

        if ($stocks->isEmpty()) {
            $this->error('No matching scrips found.');

            return self::FAILURE;
        }

        foreach ($stocks as $stock) {
            $stock->update([
                'instrument_type' => $type,
                'classification_source' => InstrumentClassifier::SOURCE_MANUAL,
            ]);

            $this->line("  {$stock->symbol} set to <info>{$type}</info> (manual — will not be auto-changed).");
        }

        return self::SUCCESS;
    }

    private function listForReview(): int
    {
        $stocks = Stock::where('instrument_type', InstrumentClassifier::UNKNOWN)
            ->orWhere('classification_source', InstrumentClassifier::SOURCE_UNRESOLVED)
            ->orderBy('symbol')
            ->get();

        if ($stocks->isEmpty()) {
            $this->info('Nothing needs manual review.');

            return self::SUCCESS;
        }

        $this->table(
            ['Symbol', 'Name', 'Type', 'Decided by'],
            $stocks->map(static fn (Stock $stock): array => [
                $stock->symbol,
                $stock->name,
                $stock->instrument_type,
                $stock->classification_source ?? '-',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
