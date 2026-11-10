<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portfolio_id')->constrained()->cascadeOnDelete();
            $table->date('analysis_date');

            $table->unsignedTinyInteger('health_score')->comment('0-100');
            $table->string('diversification_grade', 4)->nullable()->comment('A-F');
            $table->string('risk_level', 16)->nullable();

            // Snapshot of the numbers the model was reasoning about, so an old
            // analysis stays interpretable after prices move.
            $table->decimal('total_invested', 24, 2);
            $table->decimal('total_value', 24, 2);
            $table->decimal('unrealized_gain', 24, 2);
            $table->decimal('unrealized_gain_percent', 10, 2);

            $table->json('sector_allocation');
            $table->json('concentration_risks')->nullable();
            $table->json('rebalancing_actions')->nullable();
            $table->json('strengths')->nullable();
            $table->json('weaknesses')->nullable();
            $table->text('summary')->nullable();

            $table->string('model', 64);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();

            $table->timestamps();

            $table->unique(['portfolio_id', 'analysis_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_analyses');
    }
};
