<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrape_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 40)->comment('daily_prices | fundamentals | indices');
            $table->string('source', 40)->nullable();
            $table->date('trade_date')->nullable();

            $table->string('status', 24)->default('running')
                ->comment('running | success | partial | failed');

            $table->unsignedInteger('rows_found')->default(0);
            $table->unsignedInteger('rows_created')->default(0);
            $table->unsignedInteger('rows_updated')->default(0);
            $table->unsignedInteger('rows_rejected')->default(0);

            $table->json('rejections')->nullable()->comment('Sample of rows that failed validation');
            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamps();

            $table->index(['type', 'trade_date']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrape_runs');
    }
};
