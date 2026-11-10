<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->date('analysis_date');

            $table->string('signal', 16)->comment('buy | sell | hold');
            $table->unsignedTinyInteger('confidence')->comment('0-100');
            $table->string('risk_level', 16)->comment('low | medium | high');
            $table->string('time_horizon', 24)->comment('short_term | medium_term | long_term');

            // Price at the moment of analysis, so a stale signal can be judged
            // against what the model actually saw.
            $table->decimal('reference_price', 15, 2);

            $table->decimal('entry_price', 15, 2)->nullable();
            $table->decimal('entry_price_max', 15, 2)->nullable();
            $table->decimal('exit_price', 15, 2)->nullable();
            $table->decimal('stop_loss', 15, 2)->nullable();
            $table->decimal('target_1', 15, 2)->nullable();
            $table->decimal('target_2', 15, 2)->nullable();
            $table->decimal('risk_reward_ratio', 10, 2)->nullable();
            $table->decimal('expected_return_percent', 10, 2)->nullable();

            $table->text('summary')->nullable();
            $table->json('rationale')->nullable()->comment('Bull case, bear case, key drivers');
            $table->json('indicators_snapshot')->nullable();

            // Provenance — needed to compare model versions and to attribute cost.
            $table->string('model', 64);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();

            $table->timestamps();

            $table->unique(['stock_id', 'analysis_date']);
            $table->index(['analysis_date', 'signal', 'confidence']);
            $table->index(['signal', 'confidence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_signals');
    }
};
