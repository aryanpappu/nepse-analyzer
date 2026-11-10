<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_fundamentals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();

            // Crawled from the company detail page; the site publishes the
            // fiscal quarter the figures belong to, which we keep verbatim.
            // Not nullable: it is half of the upsert key, and MySQL treats NULLs
            // as distinct in a unique index, which would let every crawl insert
            // a fresh duplicate row whenever the period could not be parsed.
            $table->string('fiscal_period', 32)->default('unknown')->comment('e.g. 2081/82 Q3');
            $table->date('reported_on')->nullable();

            $table->decimal('eps', 15, 4)->nullable();
            $table->decimal('pe_ratio', 12, 4)->nullable();
            $table->decimal('book_value', 15, 4)->nullable();
            $table->decimal('pb_ratio', 12, 4)->nullable();

            $table->decimal('dividend_percent', 10, 4)->nullable();
            $table->decimal('bonus_percent', 10, 4)->nullable();
            $table->decimal('cash_dividend_percent', 10, 4)->nullable();
            $table->decimal('dividend_yield', 10, 4)->nullable();

            $table->decimal('roe', 10, 4)->nullable();
            $table->decimal('roa', 10, 4)->nullable();
            $table->decimal('net_profit', 24, 2)->nullable();
            $table->decimal('net_worth', 24, 2)->nullable();
            $table->decimal('paid_up_capital', 24, 2)->nullable();
            $table->decimal('reserve', 24, 2)->nullable();

            // Banking / finance specific — null for other sectors.
            $table->decimal('npl_percent', 10, 4)->nullable()->comment('Non-performing loans');
            $table->decimal('capital_adequacy_ratio', 10, 4)->nullable();
            $table->decimal('base_rate', 10, 4)->nullable();

            $table->string('source', 40);
            $table->json('raw')->nullable()->comment('Everything scraped, before mapping');
            $table->timestamps();

            $table->unique(['stock_id', 'fiscal_period']);
            $table->index('reported_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_fundamentals');
    }
};
