<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per scrip, per investor profile, per session.
 *
 * Stored rather than computed on read so the market can be ranked ("show me
 * every strong buy for a swing trader") with a plain indexed query, and so the
 * history of what the playbook said is auditable after the fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_verdicts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->string('investor_profile', 24);
            $table->date('analysis_date');

            $table->string('grade', 16);
            $table->decimal('score', 5, 2);

            $table->decimal('reference_price', 15, 2);
            $table->decimal('entry_low', 15, 2)->nullable();
            $table->decimal('entry_high', 15, 2)->nullable();
            $table->decimal('stop_loss', 15, 2)->nullable();
            $table->decimal('target_1', 15, 2)->nullable();
            $table->decimal('target_2', 15, 2)->nullable();
            $table->decimal('risk_reward', 10, 2)->nullable();
            $table->boolean('price_in_zone')->default(false);

            // Sub-scores and reason lines, so the UI can explain the number
            // without re-running the engine.
            $table->json('scores')->nullable();
            $table->json('reasons')->nullable();
            $table->text('summary')->nullable();

            $table->timestamps();

            $table->unique(['stock_id', 'investor_profile', 'analysis_date'], 'profile_verdicts_unique');
            // Drives the market-wide screens: best swing setups today, etc.
            $table->index(['investor_profile', 'analysis_date', 'score'], 'profile_verdicts_ranking');
            $table->index(['investor_profile', 'analysis_date', 'grade'], 'profile_verdicts_grade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_verdicts');
    }
};
