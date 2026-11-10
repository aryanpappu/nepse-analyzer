<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portfolio_id')->constrained()->cascadeOnDelete();

            $table->string('type', 24)
                ->comment('deposit | withdrawal | transfer | trade_debit | trade_credit | dividend');
            $table->string('account', 16)->comment('cash | bank');

            // Signed amount: positive credits the account, negative debits it.
            // Storing the sign rather than inferring it from `type` keeps the
            // running-balance query a plain SUM.
            $table->decimal('amount', 20, 2);

            $table->decimal('balance_after', 20, 2)->nullable();
            $table->string('reference', 120)->nullable()->comment('Broker note / voucher no.');
            $table->text('notes')->nullable();
            $table->date('transacted_on');

            $table->timestamps();

            $table->index(['portfolio_id', 'account', 'transacted_on'], 'cash_tx_account_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_transactions');
    }
};
