<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table): void {
            $table->id();

            // NEPSE ticker, e.g. NABIL, NRIC, UPPER. Always stored upper case.
            $table->string('symbol', 24)->unique();
            $table->string('name', 255);
            $table->foreignId('sector_id')->nullable()->constrained()->nullOnDelete();

            $table->string('instrument_type', 40)->default('equity')
                ->comment('equity | promoter | debenture | mutual_fund | preference');

            $table->decimal('listed_shares', 20, 2)->nullable();
            $table->decimal('paid_up_value', 12, 2)->nullable();
            $table->decimal('market_capitalization', 24, 2)->nullable();

            // Denormalised snapshot of the latest close so the listing endpoint
            // does not need a correlated subquery per row.
            $table->decimal('last_price', 15, 2)->nullable();
            $table->decimal('previous_close', 15, 2)->nullable();
            $table->decimal('change_percent', 8, 2)->nullable();
            $table->date('last_traded_on')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('delisted_at')->nullable();

            $table->timestamps();

            $table->index(['sector_id', 'is_active']);
            $table->index('last_traded_on');
            $table->index('change_percent');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
