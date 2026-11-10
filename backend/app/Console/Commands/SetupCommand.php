<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Bring a fresh install up to a working state in one command.
 *
 * The README's quick start is correct and roughly fifteen steps long: create a
 * database, generate a key, migrate, seed, then five crawls that must run in
 * order because each depends on the last. Every one of them is obvious once you
 * know the pipeline and opaque before then, and getting the order wrong fails
 * in ways that look like broken software rather than a missed step.
 *
 * So this does the lot, in order, and says what it is doing.
 *
 * ## Idempotent by design
 *
 * Every step checks whether its work already exists and skips if so, because
 * the command a newcomer reaches for after something went wrong is the one they
 * just ran. Re-running must be safe and must not re-crawl 400 sessions of
 * history that are already stored.
 *
 * ## It does not pretend
 *
 * A step that fails is reported with the reason and the command to retry, and
 * the run continues where the remaining steps do not depend on it. Ending with
 * a green "done" over a half-populated database would be worse than stopping.
 */
class SetupCommand extends Command
{
    protected $signature = 'nepse:setup
        {--fresh : Drop every table and start over. Destroys existing data.}
        {--skip-market : Set up the database but do not crawl. Useful in CI.}
        {--years=2 : How many years of price history to backfill.}';

    protected $description = 'Set up a fresh install: database, seed data, and the market crawls in the order they depend on';

    /** @var list<array{name: string, ok: bool, detail: string}> */
    private array $results = [];

    public function handle(): int
    {
        $this->line('');
        $this->line('  <options=bold>NEPSE Analyzer setup</>');
        $this->line('  <fg=gray>Each step is skipped if its work is already done, so re-running is safe.</>');
        $this->line('');

        if (! $this->checkConnections()) {
            return self::FAILURE;
        }

        $this->prepareDatabase();

        if ($this->option('skip-market') === true) {
            $this->line('  <fg=yellow>Skipping the market crawls (--skip-market).</>');
        } else {
            $this->crawlMarket();
        }

        return $this->report();
    }

    /**
     * Fail early and specifically: "connection refused" from deep inside a
     * crawl is far harder to act on than being told MySQL is not reachable.
     */
    private function checkConnections(): bool
    {
        $ok = true;

        try {
            DB::connection()->getPdo();
            $this->step('Database reachable', true, (string) config('database.connections.'.config('database.default').'.database'));
        } catch (Throwable $e) {
            $this->step('Database reachable', false, $e->getMessage());
            $this->line('');
            $this->error('  Cannot reach the database. Check DB_* in backend/.env, or start it with: docker compose up -d mysql');
            $ok = false;
        }

        try {
            Redis::connection()->ping();
            $this->step('Redis reachable', true, (string) config('database.redis.default.host'));
        } catch (Throwable $e) {
            // Not fatal: the crawls run inline here, and only the queue and
            // cache need Redis. Saying so avoids a false alarm.
            $this->step('Redis reachable', false, 'queues and caching will not work until this is fixed');
        }

        return $ok;
    }

    private function prepareDatabase(): void
    {
        if ($this->option('fresh') === true) {
            if (! $this->confirm('  --fresh drops every table and destroys existing data. Continue?', false)) {
                $this->line('  <fg=yellow>Left the database alone.</>');

                return;
            }

            $this->runStep('Dropped and rebuilt the schema', fn (): int => $this->callSilent('migrate:fresh', ['--force' => true]));
            $this->runStep('Seeded sectors and optional administrator', fn (): int => $this->callSilent('db:seed', ['--force' => true]));

            return;
        }

        $pending = $this->pendingMigrations();

        if ($pending > 0) {
            $this->runStep(sprintf('Applied %d migration(s)', $pending), fn (): int => $this->callSilent('migrate', ['--force' => true]));
        } else {
            $this->skip('Schema is up to date');
        }

        if (User::query()->exists()) {
            $this->skip('Seed data already present');
        } else {
            $this->runStep('Seeded sectors and optional administrator', fn (): int => $this->callSilent('db:seed', ['--force' => true]));
        }
    }

    /**
     * The crawls, in the order each depends on the last.
     *
     * Prices first because everything else is keyed to a scrip that must
     * already exist; indicators last because they need the history the
     * backfill brings in.
     */
    private function crawlMarket(): void
    {
        $years = max(1, (int) $this->option('years'));

        $this->maybeCrawl(
            "Today's prices",
            fn (): bool => StockPrice::query()->exists(),
            fn (): int => $this->callSilent('nepse:scrape'),
            'nepse:scrape',
        );

        $this->maybeCrawl(
            sprintf('%d year(s) of price history', $years),
            // A handful of rows means the daily scrape ran but the backfill did
            // not; 20 sessions per scrip is the rough mark of real history.
            fn (): bool => StockPrice::query()->count() > max(1, Stock::query()->count()) * 20,
            fn (): int => $this->callSilent('nepse:backfill', ['--days' => $years * 365]),
            'nepse:backfill',
        );

        $this->maybeCrawl(
            'Company fundamentals',
            fn (): bool => DB::table('stock_fundamentals')->exists(),
            fn (): int => $this->callSilent('nepse:fundamentals'),
            'nepse:fundamentals',
        );

        $this->maybeCrawl(
            'Instrument classification',
            fn (): bool => Stock::query()->where('instrument_type', '!=', 'unknown')->exists(),
            fn (): int => $this->callSilent('nepse:classify', ['--probe-nav' => true]),
            'nepse:classify',
        );

        $this->maybeCrawl(
            'Mutual fund NAVs',
            fn (): bool => DB::table('fund_navs')->exists(),
            fn (): int => $this->callSilent('nepse:navs'),
            'nepse:navs',
        );

        $this->maybeCrawl(
            'Market indices',
            fn (): bool => DB::table('market_indices')->exists(),
            fn (): int => $this->callSilent('nepse:indices'),
            'nepse:indices',
        );

        $this->maybeCrawl(
            'Technical indicators and playbook scores',
            fn (): bool => DB::table('technical_indicators')->exists(),
            fn (): int => $this->callSilent('nepse:indicators'),
            'nepse:indicators',
        );
    }

    /**
     * @param  callable(): bool  $alreadyDone
     * @param  callable(): int  $run
     */
    private function maybeCrawl(string $name, callable $alreadyDone, callable $run, string $retry): void
    {
        try {
            if ($alreadyDone()) {
                $this->skip($name);

                return;
            }
        } catch (Throwable) {
            // A missing table means the step has certainly not run.
        }

        $this->line("  <fg=gray>…</> {$name}");
        $this->runStep($name, $run, $retry);
    }

    /**
     * @param  callable(): int  $run
     */
    private function runStep(string $name, callable $run, ?string $retry = null): void
    {
        try {
            $code = $run();

            $this->step($name, $code === self::SUCCESS, $code === self::SUCCESS
                ? ''
                : 'exited with code '.$code.($retry === null ? '' : " — retry with: php artisan {$retry}"));
        } catch (Throwable $e) {
            $this->step($name, false, $e->getMessage().($retry === null ? '' : " — retry with: php artisan {$retry}"));
        }
    }

    private function step(string $name, bool $ok, string $detail = ''): void
    {
        $this->results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];

        $this->line(sprintf(
            '  %s %s%s',
            $ok ? '<fg=green>✓</>' : '<fg=red>✗</>',
            $name,
            $detail === '' ? '' : "  <fg=gray>{$detail}</>",
        ));
    }

    private function skip(string $name): void
    {
        $this->results[] = ['name' => $name, 'ok' => true, 'detail' => 'already done'];
        $this->line("  <fg=green>✓</> {$name}  <fg=gray>already done</>");
    }

    /**
     * How many migration files have not been recorded as run.
     *
     * Counted from the filesystem against the `migrations` table rather than by
     * scraping `migrate:status` output, which is formatted for humans and, as
     * PHPStan pointed out, not capturable from a command in the first place.
     */
    private function pendingMigrations(): int
    {
        $files = glob(database_path('migrations/*.php'));

        if ($files === false || $files === []) {
            return 0;
        }

        try {
            $ran = DB::table('migrations')->pluck('migration')->all();
        } catch (Throwable) {
            // No migrations table yet, so every file is pending.
            return count($files);
        }

        $pending = 0;

        foreach ($files as $file) {
            if (! in_array(basename($file, '.php'), $ran, true)) {
                $pending++;
            }
        }

        return $pending;
    }

    private function report(): int
    {
        $failed = array_values(array_filter($this->results, static fn (array $r): bool => ! $r['ok']));

        $this->line('');

        if ($failed !== []) {
            $this->line('  <fg=red;options=bold>Setup finished with problems.</>');

            foreach ($failed as $failure) {
                $this->line("    <fg=red>✗</> {$failure['name']}: {$failure['detail']}");
            }

            $this->line('');
            $this->line('  <fg=gray>Fix the above and run <options=bold>php artisan nepse:setup</> again — completed steps are skipped.</>');

            return self::FAILURE;
        }

        $this->line('  <fg=green;options=bold>Ready.</> Start these two processes, each in its own terminal:');
        $this->line('');
        $this->line('    <fg=cyan>php artisan serve</>          the API');
        $this->line('    <fg=cyan>php artisan horizon</>        queue workers');
        $this->line('    <fg=cyan>php artisan schedule:work</>  the daily crawl — <options=bold>without this, prices stop updating</>');
        $this->line('');
        $this->line('  <fg=gray>Then <options=bold>npm run dev</> in the frontend directory and open http://localhost:5173</>');

        $this->line('  <fg=gray>Create your account using the sign-in page. No default password is installed.</>');

        $this->line('');

        return self::SUCCESS;
    }
}
