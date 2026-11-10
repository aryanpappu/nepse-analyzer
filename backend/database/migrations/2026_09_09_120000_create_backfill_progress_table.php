<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per scrip that is a candidate for deep historical backfill (equity,
 * promoter, mutual fund - never debentures, which have no meaningful chart
 * history). Tracks how far back bars are confirmed to exist and whether the
 * source has any more to give, so the drip job never wastes a request on a
 * symbol that already reached the target or that the source has already
 * proven it cannot go any deeper on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backfill_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_id')->unique()->constrained()->cascadeOnDelete();

            // How far back this symbol is trying to reach, and how far back it
            // has actually confirmed a bar exists.
            $table->date('target_start_date');
            $table->date('earliest_bar_date')->nullable();

            // pending   - never attempted.
            // active    - attempted, short of target, worth retrying.
            // complete  - reached target_start_date.
            // exhausted - the source has no more history to give for this symbol.
            $table->string('status', 16)->default('pending');

            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('bars_fetched')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->string('last_result', 255)->nullable();

            $table->timestamps();

            // Drives the drip job's candidate query: which pending/active
            // symbols have gone longest without an attempt.
            $table->index(['status', 'last_attempted_at'], 'backfill_progress_pick_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backfill_progress');
    }
};
