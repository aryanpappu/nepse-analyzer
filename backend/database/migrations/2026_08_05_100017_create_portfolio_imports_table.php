<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portfolio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('source', 32)->default('meroshare');
            $table->string('status', 24)->default('awaiting_holdings')
                ->comment('awaiting_holdings | awaiting_wacc | ready | committed | cancelled');

            // Only the last four digits of the demat account are kept. The full
            // number identifies a real brokerage account and is never needed
            // again after the merge, so storing it would be pure liability.
            $table->string('demat_masked', 24)->nullable();

            $table->string('holdings_filename')->nullable();
            $table->string('wacc_filename')->nullable();

            // Parsed file contents and the merged, user-editable preview.
            $table->json('holdings_rows')->nullable();
            $table->json('wacc_rows')->nullable();
            $table->json('merged_rows')->nullable();
            $table->json('warnings')->nullable();

            $table->unsignedInteger('holdings_count')->default(0);
            $table->unsignedInteger('wacc_count')->default(0);
            $table->unsignedInteger('committed_count')->default(0);
            $table->decimal('total_cost', 24, 2)->nullable();
            $table->decimal('total_value', 24, 2)->nullable();

            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index(['portfolio_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_imports');
    }
};
