<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `reported_on` is now populated, derived from the BS `fiscal_period` string.
 *
 * The derivation is exact for an annual or fourth-quarter period — those end
 * when the fiscal year does, and App\Support\NepaliFiscalYear holds that
 * boundary — but the first three quarter ends fall on BS *month* boundaries
 * that no formula gives and that table does not cover, so they are interpolated
 * between two known fiscal-year ends and land within three days.
 *
 * That difference has to survive into the database. A date the codebase inferred
 * and a date it read must not look alike to a consumer picking the share count
 * in force at a window start, so the precision is stored beside the date rather
 * than being recoverable only by re-deriving it. NULL means `reported_on` is
 * NULL too: a period we could not date at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_fundamentals', function (Blueprint $table): void {
            $table->string('reported_on_precision', 16)
                ->nullable()
                ->after('reported_on')
                ->comment("'exact' for a published boundary, 'approximate' for an interpolated one, NULL when undated");

            // The consumer that unblocks Brinson allocation attribution asks
            // "which dated row was in force for this stock at this date", so
            // the lookup is by stock and date, not by date alone.
            $table->index(['stock_id', 'reported_on'], 'stock_fundamentals_stock_reported_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stock_fundamentals', function (Blueprint $table): void {
            $table->dropIndex('stock_fundamentals_stock_reported_idx');
            $table->dropColumn('reported_on_precision');
        });
    }
};
