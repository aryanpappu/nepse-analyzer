<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table): void {
            // A transaction entered entirely in error is voided, not hard
            // deleted, so the ledger still explains why a balance moved the
            // way it did. Voided rows are excluded from the running balance
            // and from the default ledger listing.
            $table->timestamp('voided_at')->nullable()->after('transacted_on');
            $table->string('void_reason', 255)->nullable()->after('voided_at');

            // Amending a fat-fingered value edits the row in place — so the
            // ledger reads as one right entry instead of a second, offsetting
            // one — but the pre-edit values are kept here so the correction
            // is still auditable rather than silently overwritten.
            $table->timestamp('corrected_at')->nullable()->after('void_reason');
            $table->json('original_values')->nullable()->after('corrected_at');

            // Links the two rows a transfer() call writes as one pair, so
            // amending or voiding one leg can safely apply to both instead of
            // leaving an unbalanced half-transfer behind.
            $table->uuid('transfer_group_id')->nullable()->after('original_values');
            $table->index(['portfolio_id', 'transfer_group_id'], 'cash_tx_transfer_group_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table): void {
            $table->dropIndex('cash_tx_transfer_group_idx');
            $table->dropColumn([
                'voided_at', 'void_reason', 'corrected_at', 'original_values', 'transfer_group_id',
            ]);
        });
    }
};
