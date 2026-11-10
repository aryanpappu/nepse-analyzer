<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\ScrapeException;
use App\Jobs\ScrapeCorporateActionsJob;
use App\Models\CorporateAction;
use App\Services\Scraper\CorporateCalendarService;
use App\Services\Scraper\Sources\SharesansarCorporateActionSource;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ScrapeCorporateActionsCommand extends Command
{
    protected $signature = 'nepse:corporate-actions
        {--queue : Dispatch as a queued job instead of running inline.}
        {--show : Print what is already stored instead of crawling.}
        {--days=7 : With --show, how far ahead to look for book closes.}
        {--from= : With --show, the date to measure from (YYYY-MM-DD). Defaults to the newest published_on we hold.}';

    protected $description = 'Crawl the NEPSE corporate calendar: book closes, AGMs, dividends, rights and public issues';

    public function handle(SharesansarCorporateActionSource $source): int
    {
        if ($this->option('show')) {
            $this->showUpcoming();

            return self::SUCCESS;
        }

        if ($this->option('queue')) {
            ScrapeCorporateActionsJob::dispatch();
            $this->info('Corporate calendar crawl dispatched to the queue.');

            return self::SUCCESS;
        }

        $this->info('Crawling the corporate calendar. This is rate limited, so it takes a moment...');

        try {
            $run = (new CorporateCalendarService($source))->scrape();
        } catch (ScrapeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Source', 'Published', 'Status', 'Found', 'Created', 'Updated', 'Rejected', 'Duration'],
            [[
                $run->source,
                $run->trade_date?->toDateString() ?? 'not stated by the source',
                $run->status,
                $run->rows_found,
                $run->rows_created,
                $run->rows_updated,
                $run->rows_rejected,
                round(($run->duration_ms ?? 0) / 1000, 1).' s',
            ]],
        );

        if ($source->meetingYearLabel() !== null) {
            $this->comment('Meetings were read for fiscal year '.$source->meetingYearLabel().', the year the site itself defaults to.');
        }

        if ($run->rows_rejected > 0) {
            $this->warn("{$run->rows_rejected} calendar rows were rejected:");

            foreach (array_slice((array) $run->rejections, 0, 10) as $rejection) {
                $this->line("  - {$rejection['symbol']}: {$rejection['reason']}");
            }
        }

        $this->showUpcoming();

        return self::SUCCESS;
    }

    /**
     * What is coming up, measured from a date we observed rather than from the
     * local clock — the newest `published_on` we hold, which is the last date
     * the source told us it was publishing.
     */
    private function showUpcoming(): void
    {
        $from = $this->option('from') !== null
            ? Carbon::parse((string) $this->option('from'))->startOfDay()
            : $this->latestPublished();

        if ($from === null) {
            $this->comment('Nothing stored yet. Run `php artisan nepse:corporate-actions` first.');

            return;
        }

        $days = (int) $this->option('days');

        $actions = CorporateAction::query()
            ->with('stock')
            ->bookClosingBetween($from, $days)
            ->orderBy('book_close_on')
            ->limit(40)
            ->get();

        $this->newLine();
        $this->info(sprintf('Book closes between %s and %s:', $from->toDateString(), $from->copy()->addDays($days)->toDateString()));

        if ($actions->isEmpty()) {
            $this->comment('  none');

            return;
        }

        $rows = [];

        foreach ($actions as $action) {
            $rows[] = [
                $action->stock?->symbol ?? '?',
                $action->type,
                $action->book_close_on?->toDateString(),
                (int) $from->diffInDays($action->book_close_on, false).' d',
                $action->title ?? '-',
                $action->event_on?->toDateString() ?? '-',
            ];
        }

        $this->table(['Symbol', 'Type', 'Book close', 'In', 'Detail', 'Event'], $rows);
    }

    private function latestPublished(): ?Carbon
    {
        $latest = CorporateAction::max('published_on');

        return $latest === null ? null : Carbon::parse((string) $latest)->startOfDay();
    }
}
