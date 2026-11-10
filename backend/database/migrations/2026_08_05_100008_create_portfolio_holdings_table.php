<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_holdings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portfolio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();

            // Current position. Both are derived from the transaction ledger by
            // HoldingCalculator — never written to directly by a controller.
            $table->decimal('quantity', 20, 2)->default(0);
            $table->decimal('average_cost', 15, 4)->default(0)
                ->comment('Weighted average buy price including fees');

            // Realised P/L accumulates as sells are recorded; unrealised is
            // computed on read from the latest close.
            $table->decimal('realized_gain', 20, 2)->default(0);
            $table->decimal('total_fees', 20, 2)->default(0);

            $table->date('first_purchased_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // One holding row per stock per portfolio; buys of the same scrip
            // fold into the weighted average rather than creating new rows.
            $table->unique(['portfolio_id', 'stock_id']);
            $table->index('stock_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_holdings');
    }
};
