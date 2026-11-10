<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Balance-sheet figures from the issuer's quarterly filing.
 *
 * Until now the table held only what the market pages derive: EPS, P/E, book
 * value, dividend. Those answer "what is it worth today" but not "what has it
 * accumulated", which is the question behind every side-by-side scrip
 * comparison — the distributable pool, the capital base, the equity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_fundamentals', function (Blueprint $table): void {
            // Deliberately distinct from paid_up_capital: this is the
            // balance-sheet line, which includes capital awaiting allotment.
            // NABIL files 32,057M share capital against 27,057M paid up, and
            // the published comparisons quote the former.
            $table->decimal('share_capital', 24, 2)->nullable()->after('paid_up_capital');
            $table->decimal('retained_earnings', 24, 2)->nullable()->after('share_capital');
            $table->decimal('total_equity', 24, 2)->nullable()->after('retained_earnings');
            $table->decimal('total_assets', 24, 2)->nullable()->after('total_equity');

            // Only the banking template publishes a share count; for every other
            // sector it is share capital over par value. Stored rather than
            // recomputed so the divisor used at crawl time stays auditable.
            $table->decimal('outstanding_shares', 20, 2)->nullable()->after('total_assets');

            // Retained earnings per share — the ceiling on the next dividend,
            // and the metric NEPSE investors compare banks on. Not derivable
            // from EPS, which is one period's profit rather than the pool.
            $table->decimal('distributable_eps', 15, 4)->nullable()->after('outstanding_shares');
            $table->decimal('distributable_eps_yield', 10, 4)->nullable()->after('distributable_eps');
        });
    }

    public function down(): void
    {
        Schema::table('stock_fundamentals', function (Blueprint $table): void {
            $table->dropColumn([
                'share_capital',
                'retained_earnings',
                'total_equity',
                'total_assets',
                'outstanding_shares',
                'distributable_eps',
                'distributable_eps_yield',
            ]);
        });
    }
};
