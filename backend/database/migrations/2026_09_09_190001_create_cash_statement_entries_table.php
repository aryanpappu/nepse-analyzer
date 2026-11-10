<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per statement movement that has actually been written to the
     * cash ledger, linking the broker's own identity for it to the
     * `cash_transactions` row it produced.
     *
     * This exists for one reason: **idempotency that survives a re-upload**.
     * The review step can only skip a duplicate it can see, and the unique
     * index below is what makes double-counting impossible rather than merely
     * unlikely — a second import of the same statement fails at the database
     * instead of quietly adding a million rupees of phantom capital and
     * inverting every money-weighted figure in the app.
     *
     * It is a separate table rather than a column on `cash_transactions`
     * because a statement movement is not the only way a cash row is created
     * (trades, dividends and transfers all write there), and because writing
     * it would mean teaching `CashLedger` about imports.
     */
    public function up(): void
    {
        Schema::create('cash_statement_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portfolio_id')->constrained()->cascadeOnDelete();

            // The import is history, not a dependency: deleting it must not
            // take the idempotency keys with it, or the same statement could
            // be imported again.
            $table->foreignId('cash_statement_import_id')->nullable()
                ->constrained('cash_statement_imports')->nullOnDelete();
            $table->foreignId('cash_transaction_id')->nullable()
                ->constrained('cash_transactions')->nullOnDelete();

            // sha256 of the broker's transaction id where one exists, else of
            // (date, amount, direction, reference). See CashStatementParser.
            $table->char('fingerprint', 64);

            // The broker's own transaction id, kept for support questions.
            // Not personal data — it identifies a movement, not a person.
            $table->string('external_id', 64)->nullable();

            $table->string('direction', 8)->comment('in | out');
            $table->decimal('amount', 20, 2);
            $table->date('occurred_on');
            $table->string('reference', 120)->nullable()->comment('Bill / settlement reference');
            $table->string('channel', 24)->nullable()->comment('Payment rail only, never an institution');

            $table->timestamps();

            // The guarantee. One movement, one ledger row, per portfolio.
            $table->unique(['portfolio_id', 'fingerprint'], 'cash_stmt_entry_unique');
            $table->index(['portfolio_id', 'occurred_on'], 'cash_stmt_entry_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_statement_entries');
    }
};
