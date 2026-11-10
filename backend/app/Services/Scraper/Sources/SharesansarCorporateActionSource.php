<?php

declare(strict_types=1);

namespace App\Services\Scraper\Sources;

use App\Exceptions\ScrapeException;
use App\Services\Scraper\Contracts\CorporateActionSource;
use App\Services\Scraper\CorporateActionFeedParser;
use App\Services\Scraper\CorporateActionRow;
use App\Services\Scraper\CorporateActionSnapshot;
use App\Services\Scraper\CorporateActionType;
use App\Services\Scraper\HttpFetcher;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The corporate calendar from ShareSansar: book closes, general meetings,
 * dividend proposals, rights and public issues.
 *
 * Like {@see SharesansarQuarterlySource} this is not a plain page fetch. The
 * tables are DataTables rendered client side, and the same URL as the page
 * answers with JSON when asked as an AJAX request — but only inside a session.
 * Without the cookie the endpoint returns a well-formed, entirely empty
 * payload (`recordsTotal: 0`), which is the worst possible failure mode: a
 * successful-looking crawl that silently wipes nothing and finds nothing. So
 * the page is fetched once for its cookie before any feed is read, and a feed
 * that comes back empty when the site says it has records is reported rather
 * than accepted.
 *
 * Four feeds, fetched independently:
 *
 * | Endpoint             | Params                                  |
 * |----------------------|-----------------------------------------|
 * | `/proposed-dividend` | `type=LATEST`                           |
 * | `/agm-list`          | `year`, `sector=0`, `listby`, `status`  |
 * | `/existing-issues`   | `type=<1..9>`                           |
 * | `/upcoming-issue`    | `type=<1..9>`                           |
 *
 * Two things are read off the page rather than pinned in code:
 *
 * - **The AGM year id.** `/agm-list` filters by a site-internal year id, not a
 *   BS year — `32` currently means FY 2082/2083. That number increments every
 *   Shrawan, so hardcoding it would silently return last year's meetings from
 *   the day the site rolls over. The default-selected option is read from the
 *   page's own `<select id="year">` instead.
 * - **The published date.** The dividend feed stamps every record with the date
 *   it is publishing, and that is what the run is filed under. Nothing here is
 *   dated from the local clock.
 *
 * The AGM feed is fetched once per book-close status (-1 upcoming, 0 today,
 * 1 completed) purely because that is the only way to get complete coverage —
 * the flags themselves are computed against ShareSansar's clock and are
 * deliberately discarded rather than stored.
 */
final class SharesansarCorporateActionSource implements CorporateActionSource
{
    private const DIVIDEND_PATH = '/proposed-dividend';

    private const MEETING_PATH = '/agm-list';

    private const EXISTING_ISSUE_PATH = '/existing-issues';

    private const UPCOMING_ISSUE_PATH = '/upcoming-issue';

    /** Book-close status values the meetings feed partitions its rows across. */
    private const MEETING_STATUSES = [-1, 0, 1];

    /**
     * DataTables' "no page limit". The only length these endpoints honour for a
     * whole table — see {@see feed()} for what a large positive length does.
     */
    private const ALL_ROWS = -1;

    private ?CookieJar $cookies = null;

    private ?string $meetingYearId = null;

    private ?string $meetingYearLabel = null;

    public function __construct(
        private readonly HttpFetcher $http,
        private readonly CorporateActionFeedParser $parser,
    ) {}

    public function key(): string
    {
        return 'sharesansar_corporate';
    }

    public function label(): string
    {
        return 'ShareSansar Corporate Calendar';
    }

    /**
     * The fiscal year the meetings feed is currently defaulting to, as the page
     * labels it ("2082/2083"). Null until the source has bootstrapped.
     */
    public function meetingYearLabel(): ?string
    {
        return $this->meetingYearLabel;
    }

    public function fetchCorporateActions(): CorporateActionSnapshot
    {
        $rows = [];
        $errors = [];
        $publishedOn = null;

        // Must come first, and must not be skipped. Outside a session every one
        // of these endpoints answers 200 with a well-formed, entirely empty
        // payload — a crawl that looks successful and finds nothing.
        $this->bootstrap($errors);

        // Dividends next: the feed carries the published date every other feed
        // lacks, and it is the one that must not be missing.
        try {
            $json = $this->feed(self::DIVIDEND_PATH, ['type' => 'LATEST']);
            $result = $this->parser->parseProposedDividends($json);
            $this->assertNotSilentlyEmpty(CorporateActionFeedParser::FEED_PROPOSED_DIVIDEND, $json, count($result['rows']));
            $rows = array_merge($rows, $result['rows']);
            $publishedOn = $result['publishedOn'];
        } catch (ScrapeException $e) {
            $errors[CorporateActionFeedParser::FEED_PROPOSED_DIVIDEND] = $e->getMessage();
            $this->warn(CorporateActionFeedParser::FEED_PROPOSED_DIVIDEND, $e);
        }

        $rows = array_merge($rows, $this->fetchMeetings($errors));
        $rows = array_merge($rows, $this->fetchIssues($errors));

        return new CorporateActionSnapshot(
            publishedOn: $publishedOn,
            rows: $rows,
            feedErrors: $errors,
        );
    }

    /**
     * @param  array<string, string>  $errors
     * @return list<CorporateActionRow>
     */
    private function fetchMeetings(array &$errors): array
    {
        $rows = [];

        // The meetings feed filters by a site-internal year id. Without it we
        // would be guessing which fiscal year to ask for, so the feed is
        // skipped rather than asked for the wrong one — the other three are
        // unaffected.
        if ($this->meetingYearId === null) {
            return [];
        }

        foreach (self::MEETING_STATUSES as $status) {
            try {
                $json = $this->feed(self::MEETING_PATH, [
                    'year' => (string) $this->meetingYearId,
                    'sector' => '0',
                    // Order by the date that actually matters for an alert. The
                    // status partition is relative to this column.
                    'listby' => 'BOOK_CLOSURE_DATE',
                    'status' => $status,
                ]);

                $parsed = $this->parser->parseMeetings($json);
                $this->assertNotSilentlyEmpty(CorporateActionFeedParser::FEED_AGM_LIST.':status='.$status, $json, count($parsed));
                $rows = array_merge($rows, $parsed);
            } catch (ScrapeException $e) {
                $errors[CorporateActionFeedParser::FEED_AGM_LIST.':status='.$status] = $e->getMessage();
                $this->warn(CorporateActionFeedParser::FEED_AGM_LIST, $e);
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $errors
     * @return list<CorporateActionRow>
     */
    private function fetchIssues(array &$errors): array
    {
        $rows = [];
        $wanted = $this->configuredIssueTypes();

        foreach ($wanted as $feedType) {
            foreach ([
                self::EXISTING_ISSUE_PATH => 'parseIssues',
                self::UPCOMING_ISSUE_PATH => 'parseUpcomingIssues',
            ] as $path => $method) {
                try {
                    $json = $this->feed($path, ['type' => $feedType]);
                    /** @var list<CorporateActionRow> $parsed */
                    $parsed = $this->parser->{$method}($json, $feedType);
                    $this->assertNotSilentlyEmpty(ltrim($path, '/').':type='.$feedType, $json, count($parsed));
                    $rows = array_merge($rows, $parsed);
                } catch (ScrapeException $e) {
                    $errors[ltrim($path, '/').':type='.$feedType] = $e->getMessage();
                    $this->warn(ltrim($path, '/'), $e);
                }
            }
        }

        return $rows;
    }

    /**
     * Which instrument tabs to crawl.
     *
     * Configurable because the tail of them is expensive and near-static —
     * QII tranches and migrant-worker issues are a handful of rows a year —
     * while rights and IPOs change weekly. An unrecognised value is dropped
     * rather than sent, since the endpoint answers an unknown type with an
     * empty payload instead of an error.
     *
     * @return list<int>
     */
    private function configuredIssueTypes(): array
    {
        /** @var array<int|string> $configured */
        $configured = (array) config('nepse.corporate_actions.issue_feed_types', []);

        $known = array_keys(CorporateActionType::issueFeedTypes());

        if ($configured === []) {
            return $known;
        }

        return array_values(array_intersect(
            array_map(static fn (int|string $value): int => (int) $value, $configured),
            $known,
        ));
    }

    /**
     * Establish the session, and read the meetings feed's year id off the page.
     *
     * One page fetch does both. The cookie it sets is host-wide, so every feed
     * afterwards is inside the session — which is the difference between the
     * real calendar and an empty payload. Reading the year id is best effort
     * on top of that: losing it costs the meetings feed only, whereas losing
     * the session costs everything, so the two failures are reported apart.
     *
     * @param  array<string, string>  $errors
     *
     * @throws ScrapeException When the page itself cannot be fetched — without
     *                         a session there is nothing worth attempting.
     */
    private function bootstrap(array &$errors): void
    {
        if ($this->meetingYearId !== null) {
            return;
        }

        $html = $this->http->get($this->key(), $this->url(self::MEETING_PATH), cookies: $this->session());

        if (preg_match('/<select[^>]*id=["\']year["\'][^>]*>(.*?)<\/select>/is', $html, $select) !== 1
            || (preg_match('/<option[^>]*\bselected\b[^>]*value=["\']([^"\']+)["\'][^>]*>([^<]*)</i', $select[1], $option) !== 1
                && preg_match('/<option[^>]*value=["\']([^"\']+)["\'][^>]*\bselected\b[^>]*>([^<]*)</i', $select[1], $option) !== 1)) {
            $errors[CorporateActionFeedParser::FEED_AGM_LIST] =
                'no selected year on '.self::MEETING_PATH.' — cannot tell which fiscal year the feed would '
                .'answer for, so meetings were skipped rather than fetched for a guessed year';

            Log::channel('scraper')->warning('Corporate calendar year filter unreadable', [
                'source' => $this->key(),
            ]);

            return;
        }

        $this->meetingYearId = trim($option[1]);
        $this->meetingYearLabel = trim($option[2]) === '' ? null : trim($option[2]);

        Log::channel('scraper')->info('Corporate calendar bootstrapped', [
            'source' => $this->key(),
            'meeting_year_id' => $this->meetingYearId,
            'meeting_year_label' => $this->meetingYearLabel,
        ]);
    }

    /**
     * Catch the failure mode that looks like success.
     *
     * A feed that says it holds records but yields none did not "have nothing
     * to show" — the request was wrong, most often because the session lapsed.
     * Left unchecked that is indistinguishable from a quiet week, which is the
     * same confusion between absent data and absent events that the observed
     * trading calendar exists to prevent.
     *
     * @throws ScrapeException
     */
    private function assertNotSilentlyEmpty(string $feed, string $json, int $parsed): void
    {
        if ($parsed > 0) {
            return;
        }

        $claimed = $this->parser->recordsTotal($json);

        if ($claimed !== null && $claimed > 0) {
            throw ScrapeException::tooFewRows($this->key().':'.$feed, 0, $claimed);
        }
    }

    /**
     * One DataTables request, inside the session.
     *
     * `draw`, `start` and `length` are DataTables' own paging parameters and are
     * not optional here: without them the endpoint answers with an empty
     * payload rather than the whole table.
     *
     * **The length has a trap.** Asking for a large page silently returns
     * nothing — `length=200` and above answer `recordsTotal: 0` with an empty
     * `data`, not an error and not a truncated page, even though the same
     * request at `length=50` reports 169 records. Paging around that would mean
     * four requests per feed. DataTables' own sentinel for "no limit" is `-1`,
     * and that *is* honoured: it returns the complete table in one request. So
     * -1 is the default, and it is why {@see assertNotSilentlyEmpty()} exists —
     * this is a source that answers a bad request with a plausible empty
     * calendar.
     *
     * @param  array<string, string|int>  $params
     *
     * @throws ScrapeException
     */
    private function feed(string $path, array $params): string
    {
        $length = (int) config('nepse.corporate_actions.page_length', self::ALL_ROWS);

        return $this->http->get(
            $this->key(),
            $this->url($path),
            array_merge($params, [
                'draw' => 1,
                'start' => 0,
                // Anything other than a positive page size means "all".
                'length' => $length > 0 ? $length : self::ALL_ROWS,
            ]),
            [
                // Both are load-bearing. The endpoint renders the full HTML page
                // for a plain request and only switches to JSON for an AJAX one.
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept' => 'application/json, text/javascript, */*; q=0.01',
                'Referer' => $this->url($path),
            ],
            $this->session(),
        );
    }

    /**
     * The shared cookie jar. Created lazily, then threaded through every call
     * so the session the first request established is the one the feeds use.
     */
    private function session(): CookieJar
    {
        return $this->cookies ??= $this->http->newSession();
    }

    private function warn(string $feed, ScrapeException $e): void
    {
        Log::channel('scraper')->warning('Corporate calendar feed failed', [
            'source' => $this->key(),
            'feed' => $feed,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Derived from the configured ShareSansar URL so a mirror or a scheme
     * change only has to be set in one place — same as the quarterly source.
     */
    private function url(string $path): string
    {
        $configured = (string) config('nepse.source_config.sharesansar.today_price_url');
        $parts = parse_url($configured);

        $base = isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'].'://'.$parts['host']
            : 'https://www.sharesansar.com';

        return $base.$path;
    }

    /**
     * Unused, but part of keeping this source honest about what it can date:
     * the feeds accept no date filter of any kind, so there is no "as at"
     * request to make. State is whatever the site currently publishes.
     */
    public function supportsDateFilter(): bool
    {
        return false;
    }

    public function publishedOnFrom(?Carbon $date): ?Carbon
    {
        return $date;
    }
}
