<?php

declare(strict_types=1);

namespace App\Services\Scraper\Sources;

use App\Exceptions\ScrapeException;
use App\Services\Scraper\Contracts\FundamentalsSource;
use App\Services\Scraper\FundamentalRow;
use App\Services\Scraper\HttpFetcher;
use App\Services\Scraper\QuarterlyReport;
use App\Services\Scraper\QuarterlyReportParser;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Log;

/**
 * Company fundamentals from ShareSansar's quarterly-report endpoint.
 *
 * This is the only free source that publishes the *filed* figures — share
 * capital, retained earnings, total equity, net profit, and the regulator's own
 * ratios (ROE, ROA, NPL, capital adequacy, base rate) — rather than a handful of
 * derived headline numbers. Everything the market quotes when comparing two
 * scrips side by side comes from here.
 *
 * It is not a plain page fetch. The tab is rendered client side, so this source
 * reproduces what the browser does:
 *
 *   1. GET the today-price page once, which yields three things at once — a
 *      session cookie, the CSRF token, and the embedded symbol/company-id JSON
 *      for the entire market. Doing this once instead of fetching a 260 KB
 *      company page per symbol is what makes a full-market crawl affordable.
 *   2. POST symbol + company id + sector to /company-quarterly-report.
 *
 * The sector matters: it selects the filing template, and the endpoint answers
 * with empty tables (not an error) when it is wrong. The caller's hint is tried
 * first and, if it turns out to be stale, the true sector is read from the
 * company page and the request retried once — so a mis-classified scrip
 * self-corrects instead of silently yielding nothing forever.
 */
final class SharesansarQuarterlySource implements FundamentalsSource
{
    private const REPORT_PATH = '/company-quarterly-report';

    private const COMPANY_PATH = '/company/';

    /** @var array<string, int>|null Symbol => ShareSansar company id. */
    private ?array $companyIds = null;

    private ?CookieJar $cookies = null;

    private ?string $csrfToken = null;

    /** @var array<string, string> Symbol => sector, for hints that turned out stale. */
    private array $resolvedSectors = [];

    public function __construct(
        private readonly HttpFetcher $http,
        private readonly QuarterlyReportParser $parser,
    ) {}

    public function key(): string
    {
        return 'sharesansar_quarterly';
    }

    public function label(): string
    {
        return 'ShareSansar Quarterly Report';
    }

    public function supportsFundamentals(): bool
    {
        return true;
    }

    public function fetchFundamentals(string $symbol, ?string $sectorHint = null): ?FundamentalRow
    {
        $symbol = strtoupper($symbol);

        // Deliberately not caught: a bootstrap failure is not this symbol's
        // problem, and swallowing it here would turn one broken page into 400
        // silent "no fundamentals found" misses.
        $companyId = $this->companyId($symbol);

        if ($companyId === null) {
            return null;
        }

        $sector = $this->resolvedSectors[$symbol] ?? $sectorHint;
        $report = $sector === null ? null : $this->request($symbol, $companyId, $sector);

        // Either we never had a sector, or the one we had selected the wrong
        // template. Ask the company page and try once more.
        if ($report === null || $report->isEmpty()) {
            $discovered = $this->sectorFromCompanyPage($symbol);

            if ($discovered !== null && $discovered !== $sector) {
                $this->resolvedSectors[$symbol] = $discovered;
                $sector = $discovered;
                $report = $this->request($symbol, $companyId, $discovered);
            }
        }

        if ($report === null || $report->isEmpty()) {
            return null;
        }

        return $this->toRow($report, $sector);
    }

    private function toRow(QuarterlyReport $report, ?string $sector): FundamentalRow
    {
        return new FundamentalRow(
            symbol: $report->symbol,
            fiscalPeriod: $report->fiscalPeriod ?? 'unknown',
            eps: $report->eps,
            peRatio: $report->peRatio,
            // The filing calls this "net worth per share"; it is the same
            // concept the market quotes as book value.
            bookValue: $report->bookValue,
            pbRatio: $report->pbRatio,
            roe: $report->roe,
            roa: $report->roa,
            netProfit: $report->netProfit,
            netWorth: $report->totalEquity,
            nplPercent: $report->nplPercent,
            capitalAdequacyRatio: $report->capitalAdequacyRatio,
            baseRate: $report->baseRate,
            sector: $sector,
            shareCapital: $report->shareCapital,
            retainedEarnings: $report->retainedEarnings,
            totalEquity: $report->totalEquity,
            totalAssets: $report->totalAssets,
            outstandingShares: $report->sharesInIssue(),
            distributableEps: $report->distributableEps(),
            raw: $report->raw,
        );
    }

    /**
     * One POST to the report endpoint. Returns null when the request failed in a
     * way that is this symbol's problem rather than the run's.
     */
    private function request(string $symbol, int $companyId, string $sector): ?QuarterlyReport
    {
        $base = $this->baseUrl();

        try {
            $html = $this->http->post(
                $this->key(),
                $base.self::REPORT_PATH,
                [
                    'company' => $companyId,
                    'symbol' => $symbol,
                    'sector' => $sector,
                ],
                [
                    'X-CSRF-Token' => (string) $this->csrfToken,
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Referer' => $base.self::COMPANY_PATH.strtolower($symbol),
                ],
                $this->cookies,
            );
        } catch (ScrapeException $e) {
            // An empty sector makes the endpoint 500 rather than 4xx, so a
            // failure here is routine and must not abort the crawl.
            Log::channel('scraper')->debug('Quarterly report request failed', [
                'source' => $this->key(),
                'symbol' => $symbol,
                'sector' => $sector,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->parser->parse($symbol, $html);
    }

    /**
     * Resolve the sector ShareSansar files this scrip under.
     *
     * Costs a full company page, so it is only reached when the hint was absent
     * or wrong, and the answer is memoised for the rest of the run.
     */
    private function sectorFromCompanyPage(string $symbol): ?string
    {
        $url = $this->baseUrl().self::COMPANY_PATH.strtolower($symbol);

        try {
            // reuse: SharesansarSource reads this same page for the dividend
            // split in the same pass, so whichever asks second gets it free.
            $html = $this->http->get($this->key(), $url, cookies: $this->cookies, reuse: true);
        } catch (ScrapeException $e) {
            Log::channel('scraper')->debug('Company page fetch failed', [
                'source' => $this->key(),
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (preg_match('/id=["\']sector["\'][^>]*>([^<]+)</i', $html, $m) !== 1) {
            return null;
        }

        $sector = trim($m[1]);

        return $sector === '' ? null : $sector;
    }

    /**
     * @throws ScrapeException
     */
    private function companyId(string $symbol): ?int
    {
        $this->bootstrap();

        return $this->companyIds[$symbol] ?? null;
    }

    /**
     * Fetch the session cookie, CSRF token and market-wide company-id map.
     *
     * @throws ScrapeException
     */
    private function bootstrap(): void
    {
        if ($this->companyIds !== null) {
            return;
        }

        $url = (string) config('nepse.source_config.sharesansar.today_price_url');
        $this->cookies = $this->http->newSession();

        $html = $this->http->get($this->key(), $url, cookies: $this->cookies);

        if (preg_match('/<meta\s+name=["\']_token["\']\s+content=["\']([^"\']+)["\']/i', $html, $token) !== 1) {
            throw ScrapeException::markupChanged($this->key(), $url, 'no CSRF token on the page');
        }

        $this->csrfToken = $token[1];

        // Same embedded search-box JSON the name and NAV crawlers read; it is
        // the only place a symbol/company-id pair is published for the whole
        // market in a single request.
        preg_match_all(
            '/\{"id":(\d+),"symbol":"([^"]+)","companyname":"([^"]*)"\}/',
            $html,
            $matches,
            PREG_SET_ORDER,
        );

        $ids = [];

        foreach ($matches as $match) {
            $ids[strtoupper($match[2])] = (int) $match[1];
        }

        if ($ids === []) {
            throw ScrapeException::markupChanged($this->key(), $url, 'no company id map on the page');
        }

        $this->companyIds = $ids;

        Log::channel('scraper')->info('Quarterly source bootstrapped', [
            'source' => $this->key(),
            'companies' => count($ids),
        ]);
    }

    /**
     * Derived from the configured ShareSansar URL so a mirror or a scheme change
     * only has to be set in one place.
     */
    private function baseUrl(): string
    {
        $configured = (string) config('nepse.source_config.sharesansar.today_price_url');
        $parts = parse_url($configured);

        if (! isset($parts['scheme'], $parts['host'])) {
            return 'https://www.sharesansar.com';
        }

        return $parts['scheme'].'://'.$parts['host'];
    }
}
