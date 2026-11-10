<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Signals become per-profile.
 *
 * A single signal per scrip per day forced one answer on three kinds of
 * investor. Widening the unique key lets the same scrip carry a long-term view
 * and a swing view for the same session without one overwriting the other.
 *
 * Existing rows are stamped long_term: that is the app's default lens, and the
 * prompt that produced them reasoned over months rather than days.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Each step is guarded: MySQL DDL is not transactional, so a migration
        // that fails halfway leaves the earlier steps applied and un-recorded,
        // and re-running must not trip over its own previous work.
        if (! Schema::hasColumn('stock_signals', 'investor_profile')) {
            Schema::table('stock_signals', function (Blueprint $table): void {
                $table->string('investor_profile', 24)
                    ->default('long_term')
                    ->after('stock_id');
            });
        }

        // Order matters. The stock_id foreign key relies on an index with
        // stock_id leftmost, and the old unique is currently the only one — so
        // MySQL refuses to drop it. The replacement is also stock_id-leftmost,
        // so creating it first gives the constraint something to lean on and the
        // drop then succeeds.
        if (! $this->hasIndex('stock_signals_unique')) {
            Schema::table('stock_signals', function (Blueprint $table): void {
                $table->unique(['stock_id', 'analysis_date', 'investor_profile'], 'stock_signals_unique');
            });
        }

        if ($this->hasIndex('stock_signals_stock_id_analysis_date_unique')) {
            Schema::table('stock_signals', function (Blueprint $table): void {
                $table->dropUnique('stock_signals_stock_id_analysis_date_unique');
            });
        }

        if (! $this->hasIndex('stock_signals_profile_date')) {
            Schema::table('stock_signals', function (Blueprint $table): void {
                // The signals screen filters by profile before anything else.
                $table->index(['investor_profile', 'analysis_date'], 'stock_signals_profile_date');
            });
        }
    }

    private function hasIndex(string $name): bool
    {
        return collect(DB::select('SHOW INDEX FROM stock_signals'))
            ->contains(static fn (object $row): bool => $row->Key_name === $name);
    }

    public function down(): void
    {
        // Same constraint dance in reverse: restore the narrow unique before
        // removing the wide one the foreign key is currently leaning on.
        Schema::table('stock_signals', function (Blueprint $table): void {
            $table->unique(['stock_id', 'analysis_date'], 'stock_signals_stock_id_analysis_date_unique');
        });

        Schema::table('stock_signals', function (Blueprint $table): void {
            $table->dropUnique('stock_signals_unique');
            $table->dropIndex('stock_signals_profile_date');
        });

        Schema::table('stock_signals', function (Blueprint $table): void {
            $table->dropColumn('investor_profile');
        });
    }
};
