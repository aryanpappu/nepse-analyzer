<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_indices', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80)->comment('NEPSE, Sensitive, Banking SubIndex, ...');
            $table->string('slug', 80);
            $table->date('trade_date');

            $table->decimal('value', 15, 4);
            $table->decimal('change_amount', 15, 4)->nullable();
            $table->decimal('change_percent', 8, 4)->nullable();

            $table->decimal('total_turnover', 24, 2)->nullable();
            $table->unsignedBigInteger('total_volume')->nullable();
            $table->unsignedInteger('total_transactions')->nullable();
            $table->unsignedInteger('advances')->nullable();
            $table->unsignedInteger('declines')->nullable();
            $table->unsignedInteger('unchanged')->nullable();

            $table->string('source', 40);
            $table->timestamps();

            $table->unique(['slug', 'trade_date']);
            $table->index('trade_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_indices');
    }
};
