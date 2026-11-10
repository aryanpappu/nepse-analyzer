<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The corporate calendar: book closes, general meetings, dividend proposals,
 * rights and public issues.
 *
 * One wide table with mostly-nullable columns rather than a table per event
 * type, for the same reason `stock_fundamentals` is shaped that way: the
 * question every consumer asks is cross-type ("what closes in the next three
 * days"), and answering it across five tables would mean a union in every
 * query. The columns that only some types use stay null, which is
 * meaningful — an issue with no `closes_on` is open, not closed.
 *
 * Deliberately **not** stored: the feeds' own status flags. ShareSansar
 * computes "[Closed]", -1/0/1 against its clock at render time; persisting one
 * would freeze a judgement that goes stale the next morning. The dates are
 * kept and every consumer compares them itself, which is the same rule the
 * observed trading calendar follows — record what was seen, derive the verdict
 * at the point of use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();

            $table->string('type', 32)->comment('dividend, agm, sgm, rights, ipo, fpo, mutual_fund, debenture, ...');
            $table->string('feed', 40)->comment('which ShareSansar feed published it: proposed-dividend, agm-list, existing-issues, upcoming-issue');
            $table->string('source', 40);

            // Identity within a feed, built by the source because only it knows
            // what makes a row the same row on the next crawl. Paired with
            // source in the unique key so two sources can describe one event
            // without colliding.
            $table->string('external_key', 191);

            // Every date the feeds publish. All nullable: a proposed dividend
            // has a book close but no opening date, an approved-but-unopened
            // issue has neither.
            $table->date('book_close_on')->nullable()->comment('last day to hold to qualify');
            $table->date('event_on')->nullable()->comment('AGM meeting date, or an issue opening date');
            $table->date('closes_on')->nullable()->comment('issue closing date');
            $table->date('final_on')->nullable()->comment('last date for pending applications');
            $table->date('listing_on')->nullable();
            $table->date('announced_on')->nullable();
            $table->date('distribution_on')->nullable();
            $table->date('approved_on')->nullable()->comment('SEBON approval, for an issue not yet open');

            $table->string('ratio', 32)->nullable()->comment("rights ratio as published, e.g. '1:0.56621279'");
            $table->decimal('units', 20, 2)->nullable();
            $table->decimal('issue_price', 15, 2)->nullable();

            $table->decimal('cash_dividend_percent', 12, 4)->nullable();
            $table->decimal('bonus_percent', 12, 4)->nullable();
            $table->decimal('total_dividend_percent', 12, 4)->nullable();

            $table->string('fiscal_period', 32)->nullable();
            $table->string('issue_manager', 191)->nullable();
            $table->string('title', 191)->nullable()->comment("'6th AGM', '9% bonus + 0.47% cash dividend'");
            $table->text('venue')->nullable();
            $table->text('agenda')->nullable();

            // The date the *source* said it was publishing, read off the feed —
            // never the crawl's local clock. Null when no feed stated one.
            $table->date('published_on')->nullable();

            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_key']);

            // "What closes in the next three days", across every event type.
            $table->index('book_close_on');
            $table->index('event_on');
            $table->index(['stock_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_actions');
    }
};
