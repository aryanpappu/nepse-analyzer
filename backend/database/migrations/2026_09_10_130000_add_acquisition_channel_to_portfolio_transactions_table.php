<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record how a parcel of shares was acquired.
 *
 * `type` cannot answer this: `buy` is used for an IPO allotment, a market
 * purchase and a MeroShare snapshot row alike. So profit from a lottery-allotted
 * IPO at par and profit from a scrip somebody chose were being added together
 * and reported as one number.
 *
 * A portfolio can mix broker purchases with par-priced IPO allotments and
 * rights issues. Each transaction needs its own acquisition channel.
 *
 * Defaults to `unknown`, never `secondary` — "most were probably bought on
 * market" is an assumption, and the entire point of the column is to stop
 * assumptions being reported as fact.
 *
 * The column sits on the transaction rather than the holding because one
 * position is often built from several channels: an IPO allotment topped up on
 * market later, then a bonus issue on top of that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_transactions', function (Blueprint $table): void {
            $table->string('acquisition_channel', 16)->default('unknown')->after('type')
                ->comment('ipo | secondary | bonus | rights | unknown');

            /*
             * An inferred channel and a confirmed one must stay distinguishable,
             * or re-running detection would overwrite a correction the user made
             * by hand. Null means nobody has confirmed it, so detection is free
             * to keep proposing.
             */
            $table->timestamp('channel_confirmed_at')->nullable()->after('acquisition_channel');

            $table->string('channel_basis', 40)->nullable()->after('channel_confirmed_at')
                ->comment('Why the channel was chosen: par_value | broker_statement | declared_by_type | manual');

            $table->index(['portfolio_id', 'acquisition_channel'], 'ptx_portfolio_channel_idx');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_transactions', function (Blueprint $table): void {
            $table->dropIndex('ptx_portfolio_channel_idx');
            $table->dropColumn(['acquisition_channel', 'channel_confirmed_at', 'channel_basis']);
        });
    }
};
