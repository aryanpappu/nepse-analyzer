<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Exceptions\ScrapeException;
use App\Models\CorporateAction;
use App\Models\ScrapeRun;
use App\Models\Stock;
use App\Services\Scraper\Contracts\CorporateActionSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Crawls the corporate calendar, validates it, persists it, and records the run.
 *
 * Kept out of {@see NepseScraperService} because it shares none of its
 * machinery: no failover chain (one site publishes this), no price validation,
 * no trade date. What it does share is the shape — a {@see ScrapeRun} opened
 * before the first request and closed with counts and a sample of rejections,
 * so this crawl is inspectable exactly like every other one.
 *
 * The run's `trade_date` is the date the *source* said it published, taken off
 * the payload. Null when no feed stated one, rather than filled in from the
 * local clock.
 *
 * Re-runnable by construction: rows are matched on (source, external_key) and
 * updated in place, so crawling twice in a day corrects a moved book close
 * instead of duplicating the event.
 */
final class CorporateCalendarService
{
    /** Rejections sampled onto the run record. */
    private const MAX_SAMPLED_REJECTIONS = 25;

    public function __construct(
        private readonly CorporateActionSource $source,
        private readonly CorporateActionValidator $validator = new CorporateActionValidator,
    ) {}

    /**
     * @throws ScrapeException When the source cannot be crawled at all.
     */
    public function scrape(): ScrapeRun
    {
        $run = ScrapeRun::create([
            'type' => 'corporate_actions',
            'source' => $this->source->key(),
            'status' => ScrapeRun::STATUS_RUNNING,
            'started_at' => Carbon::now(),
        ]);

        $startedAt = microtime(true);

        try {
            $snapshot = $this->source->fetchCorporateActions();
        } catch (Throwable $e) {
            $run->update([
                'status' => ScrapeRun::STATUS_FAILED,
                'error' => $e->getMessage(),
                'finished_at' => Carbon::now(),
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            throw $e instanceof ScrapeException
                ? $e
                : new ScrapeException(
                    'The corporate calendar crawl failed: '.$e->getMessage(),
                    ['source' => $this->source->key()],
                    $e,
                );
        }

        // Every feed failing is not a quiet empty calendar, it is a broken
        // crawl. Persisting nothing and calling it success is how a dead
        // crawler looks identical to a market with no announcements — the same
        // mistake the observed trading calendar exists to avoid.
        if ($snapshot->isEmpty()) {
            $run->update([
                'status' => ScrapeRun::STATUS_FAILED,
                'trade_date' => $snapshot->publishedOn?->toDateString(),
                'rows_found' => 0,
                'error' => $snapshot->feedErrors === []
                    ? 'every corporate-calendar feed returned an empty payload'
                    : 'every corporate-calendar feed failed: '.implode('; ', $snapshot->feedErrors),
                'rejections' => $this->feedErrorRejections($snapshot),
                'finished_at' => Carbon::now(),
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            throw ScrapeException::tooFewRows($this->source->key(), 0, 1);
        }

        $result = $this->persist($snapshot);

        $partial = $result['rejected'] > 0 || $snapshot->feedErrors !== [];

        $run->update([
            'trade_date' => $snapshot->publishedOn?->toDateString(),
            'status' => $partial ? ScrapeRun::STATUS_PARTIAL : ScrapeRun::STATUS_SUCCESS,
            'rows_found' => count($snapshot->rows),
            'rows_created' => $result['created'],
            'rows_updated' => $result['updated'],
            'rows_rejected' => $result['rejected'],
            'rejections' => array_merge(
                $this->feedErrorRejections($snapshot),
                $result['rejections'],
            ),
            'finished_at' => Carbon::now(),
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        Log::channel('scraper')->info('Corporate calendar scrape finished', [
            'source' => $this->source->key(),
            'published_on' => $snapshot->publishedOn?->toDateString(),
            'found' => count($snapshot->rows),
            'created' => $result['created'],
            'updated' => $result['updated'],
            'rejected' => $result['rejected'],
            'feed_errors' => array_keys($snapshot->feedErrors),
        ]);

        return $run->refresh();
    }

    /**
     * @return array{created: int, updated: int, rejected: int, repaired: int, rejections: list<array{symbol: string, reason: string}>}
     */
    private function persist(CorporateActionSnapshot $snapshot): array
    {
        $created = 0;
        $updated = 0;
        $rejected = 0;
        $repaired = 0;
        $rejections = [];

        // Kept apart from rejections: a repaired row was persisted, and folding
        // the two together would report a successful crawl as a damaged one.
        $repairs = [];

        // One query for the whole market instead of one per row. The calendar
        // routinely carries several hundred rows across a few hundred tickers.
        $stockIds = Stock::query()
            ->pluck('id', 'symbol')
            ->mapWithKeys(static fn (int $id, string $symbol): array => [strtoupper($symbol) => $id])
            ->all();

        $seen = [];

        foreach ($snapshot->rows as $row) {
            // Repair before validating, so a row is only rejected for something
            // that actually matters. See CorporateActionValidator::repair().
            $repair = $this->validator->repair($row);
            $row = $repair['row'];

            if ($repair['repaired']) {
                $repaired++;

                if (count($repairs) < self::MAX_SAMPLED_REJECTIONS) {
                    $repairs[] = [
                        'symbol' => $row->symbol,
                        'reason' => 'repaired — '.implode('; ', $repair['reasons']),
                    ];
                }
            }

            $verdict = $this->validator->validate($row, $snapshot->publishedOn);

            if ($verdict['valid'] !== true) {
                $rejected++;

                if (count($rejections) < self::MAX_SAMPLED_REJECTIONS) {
                    $rejections[] = [
                        'symbol' => $row->symbol,
                        'reason' => $row->type.': '.($verdict['reason'] ?? 'rejected'),
                    ];
                }

                continue;
            }

            $stockId = $stockIds[$row->symbol] ?? null;

            // A corporate action for a ticker we do not track is not an error —
            // the calendar covers issues for companies that have not listed yet
            // — but it has nowhere to hang, so it is counted and dropped rather
            // than inventing a stock row from a calendar entry.
            if ($stockId === null) {
                $rejected++;

                if (count($rejections) < self::MAX_SAMPLED_REJECTIONS) {
                    $rejections[] = [
                        'symbol' => $row->symbol,
                        'reason' => 'no stock with this symbol (unlisted or not yet crawled)',
                    ];
                }

                continue;
            }

            // The feeds overlap: a bonus announced at an AGM also appears on the
            // dividend feed. The external key already separates them, but a
            // single payload repeating one key would otherwise cause two writes
            // in one run, the second overwriting the first for no reason.
            if (isset($seen[$row->externalKey])) {
                continue;
            }

            $seen[$row->externalKey] = true;

            $existing = CorporateAction::query()
                ->where('source', $this->source->key())
                ->where('external_key', $row->externalKey)
                ->first();

            $attributes = $this->attributes($row, $stockId, $snapshot->publishedOn);

            if ($existing === null) {
                CorporateAction::create(array_merge($attributes, [
                    'source' => $this->source->key(),
                    'external_key' => $row->externalKey,
                ]));
                $created++;

                continue;
            }

            $existing->fill($attributes)->save();
            $updated++;
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'rejected' => $rejected,
            'repaired' => $repaired,
            'rejections' => array_merge($rejections, $repairs),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(CorporateActionRow $row, int $stockId, ?Carbon $publishedOn): array
    {
        return [
            'stock_id' => $stockId,
            'type' => $row->type,
            'feed' => $row->feed,
            'book_close_on' => $row->bookCloseOn?->toDateString(),
            'event_on' => $row->eventOn?->toDateString(),
            'closes_on' => $row->closesOn?->toDateString(),
            'final_on' => $row->finalOn?->toDateString(),
            'listing_on' => $row->listingOn?->toDateString(),
            'announced_on' => $row->announcedOn?->toDateString(),
            'distribution_on' => $row->distributionOn?->toDateString(),
            'approved_on' => $row->approvedOn?->toDateString(),
            'ratio' => $row->ratio,
            'units' => $row->units,
            'issue_price' => $row->issuePrice,
            'cash_dividend_percent' => $row->cashDividendPercent,
            'bonus_percent' => $row->bonusPercent,
            'total_dividend_percent' => $row->totalDividendPercent,
            'fiscal_period' => $row->fiscalPeriod,
            'issue_manager' => $row->issueManager,
            'title' => $row->title,
            'venue' => $row->venue,
            'agenda' => $row->agenda,
            'published_on' => $publishedOn?->toDateString(),
            'raw' => $row->raw,
        ];
    }

    /**
     * @return list<array{symbol: string, reason: string}>
     */
    private function feedErrorRejections(CorporateActionSnapshot $snapshot): array
    {
        $rejections = [];

        foreach ($snapshot->feedErrors as $feed => $error) {
            // Same {symbol, reason} shape every other run records, so existing
            // readers of scrape_runs.rejections keep working. A whole feed
            // failing has no symbol behind it, hence the feed name in its place.
            $rejections[] = ['symbol' => 'feed:'.$feed, 'reason' => $error];
        }

        return $rejections;
    }
}
