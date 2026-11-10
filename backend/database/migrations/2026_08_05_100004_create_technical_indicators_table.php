<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_indicators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->date('trade_date');

            // Momentum
            $table->decimal('rsi_14', 8, 4)->nullable();

            // Trend
            $table->decimal('macd', 15, 6)->nullable();
            $table->decimal('macd_signal', 15, 6)->nullable();
            $table->decimal('macd_histogram', 15, 6)->nullable();

            $table->decimal('sma_20', 15, 4)->nullable();
            $table->decimal('sma_50', 15, 4)->nullable();
            $table->decimal('sma_200', 15, 4)->nullable();
            $table->decimal('ema_12', 15, 4)->nullable();
            $table->decimal('ema_26', 15, 4)->nullable();

            // Volatility
            $table->decimal('bollinger_upper', 15, 4)->nullable();
            $table->decimal('bollinger_middle', 15, 4)->nullable();
            $table->decimal('bollinger_lower', 15, 4)->nullable();
            $table->decimal('atr_14', 15, 4)->nullable();

            // Volume
            $table->decimal('volume_sma_20', 24, 4)->nullable();

            // Derived summary, cheap for the API and the prompt to consume.
            $table->string('trend', 24)->nullable()->comment('bullish | bearish | neutral');
            $table->decimal('trend_strength', 5, 2)->nullable()->comment('0-100');

            $table->timestamps();

            $table->unique(['stock_id', 'trade_date']);
            $table->index(['trade_date', 'rsi_14']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_indicators');
    }
};
