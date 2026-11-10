<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user comparison settings.
 *
 * The deposit benchmark rate has lived only in config, so every user was
 * compared against the same number. Deposit rates differ by bank and by
 * negotiation, and the honest question — "did this beat leaving the money in
 * *my* bank" — needs the user's own rate.
 *
 * Nullable on purpose: null means "use the configured default", which is not
 * the same as a user deliberately setting 0%.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('benchmark_fd_rate', 5, 2)->nullable()->after('role')
                ->comment('Personal deposit rate for the vs-FD benchmark; null = use config default');

            $table->string('benchmark_index_slug', 80)->nullable()->after('benchmark_fd_rate')
                ->comment('Which index to benchmark against; null = use config default');

            $table->string('investor_class', 24)->nullable()->after('benchmark_index_slug')
                ->comment('individual | institution — sets the capital gains rate band');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['benchmark_fd_rate', 'benchmark_index_slug', 'investor_class']);
        });
    }
};
