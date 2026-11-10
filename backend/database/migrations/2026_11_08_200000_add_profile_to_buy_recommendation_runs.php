<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guarded steps: MySQL DDL is not transactional, so a half-applied run must be resumable.
        if (! Schema::hasColumn('buy_recommendation_runs', 'investor_profile')) {
            Schema::table('buy_recommendation_runs', function (Blueprint $table): void {
                // Legacy runs mixed signal profiles and must not be labelled as a specific strategy.
                $table->string('investor_profile', 24)->nullable();
            });
        }

        // The replacement index must exist BEFORE the old one is dropped: the old unique index
        // backs the portfolio_id foreign key, and InnoDB refuses to drop it while it is needed.
        if (! Schema::hasIndex('buy_recommendation_runs', 'recommendation_runs_portfolio_date_profile_unique')) {
            Schema::table('buy_recommendation_runs', function (Blueprint $table): void {
                $table->unique(['portfolio_id', 'analysis_date', 'investor_profile'], 'recommendation_runs_portfolio_date_profile_unique');
            });
        }

        if (Schema::hasIndex('buy_recommendation_runs', 'buy_recommendation_runs_portfolio_id_analysis_date_unique')) {
            Schema::table('buy_recommendation_runs', function (Blueprint $table): void {
                $table->dropUnique(['portfolio_id', 'analysis_date']);
            });
        }
    }

    public function down(): void
    {
        if (DB::table('buy_recommendation_runs')->select('portfolio_id', 'analysis_date')
            ->groupBy('portfolio_id', 'analysis_date')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot roll back profile support while multiple strategies exist for one portfolio/date. Export and reconcile those runs first.');
        }

        // Reverse order: restore the old index first so the foreign key is never left unindexed.
        Schema::table('buy_recommendation_runs', function (Blueprint $table): void {
            $table->unique(['portfolio_id', 'analysis_date']);
        });
        Schema::table('buy_recommendation_runs', function (Blueprint $table): void {
            $table->dropUnique('recommendation_runs_portfolio_date_profile_unique');
            $table->dropColumn('investor_profile');
        });
    }
};
