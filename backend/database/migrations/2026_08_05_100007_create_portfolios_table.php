<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('currency', 3)->default('NPR');

            // Un-deployed cash, split by where it actually sits. The sum is the
            // investable "dry powder" the buy-recommendation prompt is allowed
            // to allocate. Kept as two columns rather than one because the
            // owner reconciles them against different real-world statements.
            $table->decimal('cash_in_hand', 20, 2)->default(0);
            $table->decimal('bank_balance', 20, 2)->default(0);

            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_default']);
            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolios');
    }
};
