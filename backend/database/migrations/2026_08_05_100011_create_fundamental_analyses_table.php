<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fundamental_analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->date('analysis_date');

            $table->string('valuation', 24)->nullable()
                ->comment('undervalued | fairly_valued | overvalued');
            $table->unsignedTinyInteger('quality_score')->nullable()->comment('0-100');
            $table->unsignedTinyInteger('financial_health_score')->nullable()->comment('0-100');
            $table->unsignedTinyInteger('dividend_sustainability_score')->nullable()->comment('0-100');

            $table->decimal('fair_value_low', 15, 2)->nullable();
            $table->decimal('fair_value_high', 15, 2)->nullable();
            $table->decimal('sector_pe_comparison', 12, 4)->nullable();

            $table->json('strengths')->nullable();
            $table->json('concerns')->nullable();
            $table->json('metrics_snapshot')->nullable();
            $table->text('summary')->nullable();

            $table->string('model', 64);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();

            $table->timestamps();

            $table->unique(['stock_id', 'analysis_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fundamental_analyses');
    }
};
