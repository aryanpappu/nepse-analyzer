<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table): void {
            // The NAV feed is keyed by the data source's own company id, not by
            // ticker, so it has to be stored to be able to fetch NAV at all.
            $table->unsignedBigInteger('external_id')->nullable()->after('symbol');
            $table->index('external_id');
        });

        Schema::create('fund_navs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->date('nav_date');

            // NAV per unit. Nepali funds issue units at NPR 10 par, so this is
            // a small number and needs the extra decimal places to be useful.
            $table->decimal('nav', 15, 4);

            // Market price on the same date, when known — the pair is what
            // makes the premium/discount meaningful.
            $table->decimal('market_price', 15, 2)->nullable();
            $table->decimal('premium_discount_percent', 10, 4)->nullable()
                ->comment('(market_price - nav) / nav * 100; negative means trading at a discount');

            $table->string('source', 40);
            $table->timestamps();

            $table->unique(['stock_id', 'nav_date']);
            $table->index('nav_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_navs');

        Schema::table('stocks', function (Blueprint $table): void {
            $table->dropIndex(['external_id']);
            $table->dropColumn('external_id');
        });
    }
};
