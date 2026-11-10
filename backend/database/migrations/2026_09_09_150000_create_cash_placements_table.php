<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_placements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portfolio_id')->constrained()->cascadeOnDelete();

            // Discriminator: only 'fixed_deposit' is interest-bearing today.
            // 'pledged_shares' is reserved so the other Nepali meaning of
            // "collateral" (shares pledged against a margin loan) can be added
            // later without a migration — the column already accepts it.
            $table->string('kind', 24)->default('fixed_deposit')
                ->comment('fixed_deposit | pledged_shares (reserved)');

            $table->string('institution', 120)->nullable()->comment('Bank / institution name');
            $table->string('reference', 120)->nullable()->comment('Account / receipt / certificate no.');

            // Money as decimal, never float.
            $table->decimal('principal', 20, 2);
            $table->decimal('interest_rate', 6, 3)->comment('Annual percent, e.g. 8.250');

            $table->date('start_date');
            $table->date('maturity_date')->nullable();

            // When the placement was actually closed (withdrawn / broken
            // early), if before maturity. Distinct from maturity_date so
            // accrual can stop on the day money actually moved rather than
            // continuing to the placement's original maturity or to today.
            $table->date('closed_on')->nullable();

            $table->string('compounding_basis', 16)->default('simple')
                ->comment('simple | monthly | quarterly | semi_annually | annually');
            $table->string('payout', 16)->default('paid_out')
                ->comment('paid_out | reinvested');
            $table->string('status', 16)->default('active')
                ->comment('active | matured | withdrawn | broken_early');

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['portfolio_id', 'status']);
            $table->index(['portfolio_id', 'maturity_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_placements');
    }
};
