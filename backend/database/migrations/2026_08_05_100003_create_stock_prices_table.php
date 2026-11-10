<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->date('trade_date');

            $table->decimal('open', 15, 2)->nullable();
            $table->decimal('high', 15, 2)->nullable();
            $table->decimal('low', 15, 2)->nullable();
            $table->decimal('close', 15, 2);
            $table->decimal('previous_close', 15, 2)->nullable();

            // NEPSE publishes both the last traded price and a volume weighted
            // average; the VWAP is what the index calculation actually uses.
            $table->decimal('average_price', 15, 2)->nullable();

            $table->decimal('change_amount', 15, 2)->nullable();
            $table->decimal('change_percent', 8, 2)->nullable();

            $table->unsignedBigInteger('volume')->default(0);
            $table->decimal('turnover', 24, 2)->default(0);
            $table->unsignedInteger('transactions')->nullable();

            $table->decimal('fifty_two_week_high', 15, 2)->nullable();
            $table->decimal('fifty_two_week_low', 15, 2)->nullable();

            $table->string('source', 40)->comment('Which price source produced this row');
            $table->timestamps();

            // One row per stock per trading day — the scraper upserts on this.
            $table->unique(['stock_id', 'trade_date']);

            // Chart queries are always "latest N days for one stock".
            $table->index(['stock_id', 'trade_date', 'close'], 'stock_prices_history_idx');

            // Market-wide "top gainers / most active today" queries.
            $table->index(['trade_date', 'turnover']);
            $table->index(['trade_date', 'change_percent']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_prices');
    }
};
