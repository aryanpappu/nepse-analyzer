<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portfolio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();

            $table->string('type', 16)->comment('buy | sell | bonus | dividend | rights');
            $table->decimal('quantity', 20, 2);
            $table->decimal('price', 15, 4)->default(0)
                ->comment('Zero for bonus shares and cash dividends');

            // Nepali brokers charge a commission plus SEBON and DP fees; keeping
            // them separate from price is what makes average cost honest.
            $table->decimal('commission', 15, 2)->default(0);
            $table->decimal('other_fees', 15, 2)->default(0);

            // Realised P/L for this specific sell, computed at write time from
            // the average cost that was in force.
            $table->decimal('realized_gain', 20, 2)->nullable();

            $table->date('transacted_on');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['portfolio_id', 'transacted_on']);
            $table->index(['portfolio_id', 'stock_id', 'transacted_on'], 'pf_tx_position_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_transactions');
    }
};
