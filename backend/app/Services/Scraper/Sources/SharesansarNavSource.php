<?php

declare(strict_types=1);

namespace App\Services\Scraper\Sources;

use App\Exceptions\ScrapeException;
use App\Services\Scraper\HttpFetcher;
use App\Support\Numeric;
use Illuminate\Support\Facades\Log;

/**
 * Daily NAV history for closed-end mutual funds.
 *
 * Mutual fund units are the one instrument on NEPSE where the traded price is
 * not the primary valuation input — what matters is the net asset value and how
 * far the price sits from it. The NAV chart behind the mutual fund page serves
 * that series as plain JSON.
 *
 * The endpoint is keyed by the site's internal company id rather than by
 * ticker, so `resolveCompanyIds()` scrapes the symbol-to-id map that every page
 * embeds for its search box.
 */
final class SharesansarNavSource
{
    private const NAV_PATH = '/mutual-fund-navs-chart';

    private const LIST_PATH = '/mutual-fund-navs';

    /** @var array<string, int>|null */
    private ?array $companyIds = null;

    public function __construct(
        private readonly HttpFetcher $http,
    ) {}

    public function key(): string
    {
        return 'sharesansar_nav';
    }

    /**
     * Symbol => internal company id, for every listed scrip.
     *
     * @return array<string, int>
     */
    public function resolveCompanyIds(): array
    {
        if ($this->companyIds !== null) {
            return $this->companyIds;
        }

        try {
            $html = $this->http->get($this->key(), $this->baseUrl().self::LIST_PATH);
        } catch (ScrapeException $e) {
            Log::channel('scraper')->warning('Could not load the company id map', [
                'error' => $e->getMessage(),
            ]);

            return $this->companyIds = [];
        }

        if (! preg_match_all(
            '/\{"id":(\d+),"symbol":"([^"]+)","companyname":"[^"]*"\}/',
            $html,
            $matches,
            PREG_SET_ORDER,
        )) {
            return $this->companyIds = [];
        }

        $ids = [];

        foreach ($matches as $match) {
            $symbol = strtoupper(trim($match[2]));

            if ($symbol !== '') {
                $ids[$symbol] = (int) $match[1];
            }
        }

        return $this->companyIds = $ids;
    }

    /**
     * Daily NAV series for one fund, oldest first.
     *
     * @return list<array{date: string, nav: float}>
     */
    public function fetchNavHistory(string $symbol, ?int $companyId = null): array
    {
        $symbol = strtoupper(trim($symbol));
        $companyId ??= $this->resolveCompanyIds()[$symbol] ?? null;

        if ($companyId === null) {
            Log::channel('scraper')->info('No company id for fund, skipping NAV', ['symbol' => $symbol]);

            return [];
        }

        try {
            $body = $this->http->get(
                $this->key(),
                $this->baseUrl().self::NAV_PATH,
                // `criteria` is required by the endpoint but does not restrict
                // the range; the full history comes back regardless.
                ['id' => $companyId, 'criteria' => 12],
                [
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Referer' => $this->baseUrl().self::LIST_PATH,
                ],
            );
        } catch (ScrapeException $e) {
            Log::channel('scraper')->warning('NAV fetch failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return [];
        }

        // The endpoint answers {"message":"Bad request."} for a bad id.
        if (isset($payload['message'])) {
            Log::channel('scraper')->info('NAV endpoint rejected the request', [
                'symbol' => $symbol,
                'company_id' => $companyId,
                'message' => $payload['message'],
            ]);

            return [];
        }

        $series = [];

        foreach ($payload as $point) {
            if (! is_array($point)) {
                continue;
            }

            $date = $point['date'] ?? null;
            $nav = Numeric::parse(isset($point['value']) ? (string) $point['value'] : null);

            if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }

            if ($nav === null || $nav <= 0) {
                continue;
            }

            $series[$date] = ['date' => $date, 'nav' => round($nav, 4)];
        }

        ksort($series);

        return array_values($series);
    }

    public function latestNav(string $symbol, ?int $companyId = null): ?float
    {
        $series = $this->fetchNavHistory($symbol, $companyId);

        if ($series === []) {
            return null;
        }

        return (float) end($series)['nav'];
    }

    private function baseUrl(): string
    {
        $configured = (string) config('nepse.source_config.sharesansar.today_price_url');
        $parts = parse_url($configured);

        if (isset($parts['scheme'], $parts['host'])) {
            return $parts['scheme'].'://'.$parts['host'];
        }

        return 'https://www.sharesansar.com';
    }
}
