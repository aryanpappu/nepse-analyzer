<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staging for a broker cash statement import.
     *
     * Deliberately a sibling of `portfolio_imports` rather than more columns
     * on it. The two share a shape — parsed rows, an editable merged preview,
     * row statuses, a commit step — but nothing else: one produces positions
     * through `HoldingCalculator`, the other cash movements through
     * `CashLedger`, and a single table would need every column of both
     * nullable and a `source` discriminator deciding which half means
     * anything. The MeroShare wizard's machinery is reused as a *pattern*;
     * its storage is not overloaded.
     */
    public function up(): void
    {
        Schema::create('cash_statement_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portfolio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('format', 32)->comment('tms_deposit_log | broker_ledger');
            $table->string('status', 24)->default('ready')
                ->comment('ready | committed | cancelled');

            // Which of the portfolio's two cash accounts the movements land
            // in. Broker money is normally the bank-linked account.
            $table->string('account', 16)->default('bank')->comment('cash | bank');

            $table->string('filename')->nullable();

            // Rows exactly as parsed, and the user-editable review copy. The
            // parsed set is kept so re-running the merge after an edit cannot
            // drift from what the file actually said.
            $table->json('parsed_rows')->nullable();
            $table->json('merged_rows')->nullable();
            $table->json('warnings')->nullable();

            // Whether this statement's window touches one already imported.
            // Stored rather than recomputed so the decision the user made is
            // still visible after the fact.
            $table->json('overlap')->nullable();

            $table->unsignedInteger('parsed_count')->default(0);
            $table->unsignedInteger('ready_count')->default(0);
            $table->unsignedInteger('excluded_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('committed_count')->default(0);

            $table->decimal('money_in', 24, 2)->nullable();
            $table->decimal('money_out', 24, 2)->nullable();
            $table->decimal('net_injected', 24, 2)->nullable();

            $table->date('first_movement_on')->nullable();
            $table->date('last_movement_on')->nullable();

            // The post-commit reconciliation against the trade ledger.
            $table->json('reconciliation')->nullable();

            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index(['portfolio_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_statement_imports');
    }
};
