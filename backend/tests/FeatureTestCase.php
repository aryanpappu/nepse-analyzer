<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

/**
 * Base class for HTTP-level tests.
 *
 * Database isolation is deliberately scoped to *this* class rather than to
 * `phpunit.xml`. The project's `<php><env>` block leaves `DB_CONNECTION`
 * commented out on purpose, so the pure-unit suite runs in whatever
 * environment `.env` describes; flipping that globally would silently move the
 * connection underneath every other test and every other process running the
 * suite. Instead the connection is rewritten in {@see createApplication()},
 * after the framework has booted and before `RefreshDatabase` migrates, so
 * only tests extending this class ever see sqlite.
 *
 * Migrations are run from an explicit file list rather than from the directory,
 * because one of them cannot run on sqlite — see
 * {@see MYSQL_ONLY_MIGRATIONS}.
 *
 * One divergence to keep in mind when writing assertions: sqlite has no DATE
 * type, so Laravel writes a `$table->date()` column as `Y-m-d H:i:s`. Two
 * consequences, neither of which is an application fault:
 *
 * - `assertDatabaseHas(['transacted_on' => '2026-09-09'])` will not match.
 *   Read the value back through the model (which casts it) and compare
 *   `->toDateString()`.
 * - A query that compares such a column against a bare `Y-m-d` string
 *   (`where('trade_date', '<=', $date->toDateString())`) drops rows dated on
 *   the boundary day itself, where MySQL's DATE column would include them.
 *   Assert with a tolerance rather than pinning an exact figure that only the
 *   sqlite storage shape produces.
 */
abstract class FeatureTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Migrations that only run on MySQL, excluded from the sqlite schema build
     * and replaced by {@see applyMysqlOnlySchema()}.
     *
     * `2026_08_21_110000_add_investor_profile_to_stock_signals_table` inspects
     * the existing indexes with `SHOW INDEX FROM stock_signals` so that it can
     * be re-run safely after a half-applied non-transactional DDL failure.
     * That statement is MySQL-only and sqlite rejects it outright, so the
     * migration is skipped here and its schema effect reproduced below. The
     * migration itself is application code and is not modified.
     *
     * @var list<string>
     */
    private const MYSQL_ONLY_MIGRATIONS = [
        '2026_08_21_110000_add_investor_profile_to_stock_signals_table.php',
    ];

    /**
     * Assert a numeric JSON value without caring how PHP encoded it.
     *
     * `json_encode()` writes 60000.0 as `60000`, so `assertJsonPath()`'s
     * strict comparison fails against a float expectation for every whole
     * rupee figure. Money is compared numerically here instead.
     */
    protected function assertJsonNumber(TestResponse $response, string $path, float $expected): void
    {
        $actual = data_get($response->json(), $path);

        $this->assertIsNumeric($actual, "Expected a number at [{$path}].");
        $this->assertEqualsWithDelta($expected, (float) $actual, 0.005, "Unexpected value at [{$path}].");
    }

    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = parent::createApplication();

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');

        return $app;
    }

    /**
     * Same contract as the trait's own version — migrate once per process,
     * then wrap each test in a transaction — but built from a filtered
     * migration list.
     */
    protected function refreshTestDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh', [
                '--path' => $this->migrationFiles(),
                '--realpath' => true,
            ]);

            $this->applyMysqlOnlySchema();

            $this->app[Kernel::class]->setArtisan(null);

            RefreshDatabaseState::$migrated = true;
        }

        $this->beginDatabaseTransaction();
    }

    /**
     * Every migration except the ones sqlite cannot run.
     *
     * @return list<string>
     */
    private function migrationFiles(): array
    {
        $files = glob(database_path('migrations/*.php'));

        return array_values(array_filter(
            $files === false ? [] : $files,
            static fn (string $path): bool => ! in_array(
                basename($path),
                self::MYSQL_ONLY_MIGRATIONS,
                true,
            ),
        ));
    }

    /**
     * Reproduce, in sqlite terms, the schema the skipped MySQL-only migration
     * would have produced: signals are per investor profile, so the unique key
     * is widened from (stock, date) to (stock, date, profile).
     */
    private function applyMysqlOnlySchema(): void
    {
        if (Schema::hasColumn('stock_signals', 'investor_profile')) {
            return;
        }

        Schema::table('stock_signals', function (Blueprint $table): void {
            $table->string('investor_profile', 24)->default('long_term');
        });

        Schema::table('stock_signals', function (Blueprint $table): void {
            $table->unique(['stock_id', 'analysis_date', 'investor_profile'], 'stock_signals_unique');
        });

        Schema::table('stock_signals', function (Blueprint $table): void {
            $table->dropUnique('stock_signals_stock_id_analysis_date_unique');
        });

        Schema::table('stock_signals', function (Blueprint $table): void {
            $table->index(['investor_profile', 'analysis_date'], 'stock_signals_profile_date');
        });
    }
}
