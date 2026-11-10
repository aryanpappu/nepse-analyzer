<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buy_recommendation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portfolio_id')->constrained()->cascadeOnDelete();
            $table->date('analysis_date');

            // The budget the model was told it could deploy, snapshotted so an
            // old recommendation set stays interpretable after cash moves.
            $table->decimal('investable_cash', 20, 2);
            $table->decimal('allocated_amount', 20, 2)->default(0);
            $table->decimal('reserve_amount', 20, 2)->default(0)
                ->comment('Cash the model deliberately left undeployed');

            $table->text('strategy_summary')->nullable();
            $table->json('avoid_list')->nullable();

            $table->string('model', 64);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();

            $table->timestamps();

            $table->unique(['portfolio_id', 'analysis_date']);
        });

        Schema::create('buy_recommendations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('buy_recommendation_runs')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('rank')->default(1);
            $table->string('action', 24)->default('buy')->comment('buy | add | avoid');

            $table->decimal('suggested_quantity', 20, 2);
            $table->decimal('suggested_price', 15, 2);
            $table->decimal('estimated_cost', 20, 2);
            $table->decimal('allocation_percent', 8, 2)->nullable();

            $table->decimal('stop_loss', 15, 2)->nullable();
            $table->decimal('target_price', 15, 2)->nullable();
            $table->unsignedTinyInteger('conviction')->nullable()->comment('0-100');
            $table->string('risk_level', 16)->nullable();
            $table->string('time_horizon', 24)->nullable();
            $table->text('reason')->nullable();

            $table->timestamps();

            $table->index(['run_id', 'rank']);
            $table->index('stock_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buy_recommendations');
        Schema::dropIfExists('buy_recommendation_runs');
    }
};
