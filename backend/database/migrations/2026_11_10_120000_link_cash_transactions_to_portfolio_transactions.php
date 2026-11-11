<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a trade's cash settlement to the trade itself.
 *
 * Without the link, deleting a holding removed its trades but left their
 * settlement rows in the cash ledger, so the balance kept money for a sale
 * that no longer existed. Nullable because most cash rows (deposits, imported
 * statement settlements) have no single trade behind them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('cash_transactions', 'portfolio_transaction_id')) {
            return;
        }

        Schema::table('cash_transactions', function (Blueprint $table): void {
            $table->foreignId('portfolio_transaction_id')
                ->nullable()
                ->after('portfolio_id')
                ->constrained('portfolio_transactions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('cash_transactions', 'portfolio_transaction_id')) {
            return;
        }

        Schema::table('cash_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('portfolio_transaction_id');
        });
    }
};
