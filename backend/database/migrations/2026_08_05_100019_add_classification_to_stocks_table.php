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
            // Which signal decided instrument_type. 'manual' means a human set
            // it, and the automatic classifier must never override that.
            $table->string('classification_source', 24)->nullable()->after('instrument_type')
                ->comment('nav_probe | sector | name | symbol | manual | unresolved');

            // Whether the scrip publishes a NAV. Null = never checked. This is
            // the functional test for "is this a fund", so the answer is cached
            // rather than re-probed on every classification pass.
            $table->boolean('publishes_nav')->nullable()->after('classification_source');
            $table->timestamp('nav_checked_at')->nullable()->after('publishes_nav');

            $table->index('classification_source');
        });
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table): void {
            $table->dropIndex(['classification_source']);
            $table->dropColumn(['classification_source', 'publishes_nav', 'nav_checked_at']);
        });
    }
};
