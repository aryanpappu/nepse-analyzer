<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per calendar date the backfill has learned something about - the
 * single record of what was actually tried and what was actually seen.
 *
 * NEPSE's trading week is not a constant: it was Sunday-Thursday and has since
 * moved to Monday-Friday, with public holidays layered on top on the Bikram
 * Sambat calendar, which no formula reproduces. So whether a date is a session
 * is never decided from a weekday rule here - it is observed. A date with at
 * least one real bar is a trading day, full stop; a date inside a window we
 * have actually checked (see the "liquid basket" evidence in
 * HistoryBackfillService) with nothing found is a holiday; anything we have
 * not yet checked, or checked unsuccessfully, stays unknown and eligible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_calendar_days', function (Blueprint $table): void {
            $table->id();
            $table->date('trade_date')->unique();

            // never_attempted - nothing has looked at this date yet.
            // attempted       - looked, outcome not yet resolved either way.
            // data_found      - at least one bar was observed.
            // no_data         - checked inside a trusted window, nothing found.
            $table->string('sync_status', 20)->default('never_attempted');

            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();

            // Whole-market: how many distinct symbols have a bar for this date.
            $table->unsignedInteger('observed_symbol_count')->default(0);

            // trading_day | holiday | unknown
            $table->string('classification', 16)->default('unknown');
            // observed | manual | null (not yet classified)
            $table->string('classified_by', 16)->nullable();
            $table->string('notes', 255)->nullable();

            $table->timestamps();

            $table->index('classification');
            $table->index(['sync_status', 'last_attempted_at'], 'market_calendar_days_pick_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_calendar_days');
    }
};
