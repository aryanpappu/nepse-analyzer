<?php

declare(strict_types=1);

namespace App\Services\Scraper\Sources;

use App\Exceptions\ScrapeException;
use App\Services\Scraper\FundamentalRow;
use App\Services\Scraper\IndexSnapshot;
use App\Services\Scraper\LiveIndexParser;
use App\Services\Scraper\PriceRow;
use App\Services\Scraper\PriceSnapshot;
use App\Support\Numeric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Primary price source.
 *
 * The "today share price" page is server rendered and carries full OHLC, VWAP,
 * volume, turnover and 52-week range for every listed scrip in one request,
 * which makes it the cheapest complete snapshot available without an API.
 */
final class SharesansarSource extends AbstractHtmlSource
{
    public function key(): string
    {
        return 'sharesansar';
    }

    public function label(): string
    {
        return (string) ($this->config()['label'] ?? 'ShareSansar');
    }

    public function supportsFundamentals(): bool
    {
        return true;
    }

    /**
     * @throws ScrapeException
     */
    public function fetchDailyPrices(?Carbon $date = null): PriceSnapshot
    {
        $url = (string) $this->config()['today_price_url'];

        // The date is sent, but this page ignores it on a plain GET and always
        // renders the last completed session. The session date is therefore
        // read back off the page below and that is what gets persisted.
        $query = $date !== null ? ['date' => $date->toDateString()] : [];

        $page = $this->http->crawl($this->key(), $url, $query);

        $table = $this->requireTable($page, 'table#headFixed', 'the daily price table');
        $map = $this->headerMap($table);

        if ($map === []) {
            throw ScrapeException::markupChanged($this->key(), 'price table has no readable header row');
        }

        $columns = [
            'symbol' => $this->columnIndex($map, ['symbol']),
            'open' => $this->columnIndex($map, ['open']),
            'high' => $this->columnIndex($map, ['high']),
            'low' => $this->columnIndex($map, ['low']),
            'close' => $this->columnIndex($map, ['close']),
            'ltp' => $this->columnIndex($map, ['ltp']),
            'vwap' => $this->columnIndex($map, ['vwap']),
            'volume' => $this->columnIndex($map, ['vol', 'volume']),
            'prev_close' => $this->columnIndex($map, ['prev close', 'previous close']),
            'turnover' => $this->columnIndex($map, ['turnover']),
            'transactions' => $this->columnIndex($map, ['trans', 'transactions']),
            // Must be the percent column, never the rupee "Diff" column.
            'change_percent' => $this->columnIndex($map, ['diff percent', 'change percent', 'percent change']),
            'week_high' => $this->columnIndex($map, ['52 weeks high']),
            'week_low' => $this->columnIndex($map, ['52 weeks low']),
        ];

        if ($columns['symbol'] === null || ($columns['close'] === null && $columns['ltp'] === null)) {
            throw ScrapeException::markupChanged(
                $this->key(),
                'price table is missing the symbol or close column; headers seen: '.implode(', ', array_keys($map)),
            );
        }

        $rows = [];

        foreach ($this->rows($table) as $cells) {
            $symbol = $this->normalizeSymbol($this->cell($cells, $columns['symbol']));

            if ($symbol === null) {
                continue;
            }

            // Prefer the official close; fall back to last traded price for
            // scrips where the close column is blank.
            $close = $this->numberAt($cells, $columns['close'])
                ?? $this->numberAt($cells, $columns['ltp']);

            if ($close === null || $close <= 0) {
                continue;
            }

            $rows[] = new PriceRow(
                symbol: $symbol,
                name: null,
                sector: null,
                close: $close,
                open: $this->numberAt($cells, $columns['open']),
                high: $this->numberAt($cells, $columns['high']),
                low: $this->numberAt($cells, $columns['low']),
                previousClose: $this->numberAt($cells, $columns['prev_close']),
                averagePrice: $this->numberAt($cells, $columns['vwap']),
                changePercent: $this->numberAt($cells, $columns['change_percent']),
                volume: (int) ($this->numberAt($cells, $columns['volume']) ?? 0),
                turnover: $this->numberAt($cells, $columns['turnover']) ?? 0.0,
                transactions: $this->numberAt($cells, $columns['transactions']) === null
                    ? null
                    : (int) $this->numberAt($cells, $columns['transactions']),
                fiftyTwoWeekHigh: $this->numberAt($cells, $columns['week_high']),
                fiftyTwoWeekLow: $this->numberAt($cells, $columns['week_low']),
            );
        }

        $sessionDate = $this->sessionDate($page);

        Log::channel('scraper')->info('Parsed daily prices', [
            'source' => $this->key(),
            'rows' => count($rows),
            'session_date' => $sessionDate?->toDateString(),
        ]);

        return new PriceSnapshot(
            tradeDate: $sessionDate ?? $this->assumedTradingDay(),
            rows: $rows,
            dateIsAuthoritative: $sessionDate !== null,
        );
    }

    /**
     * The session the page is actually showing.
     *
     * Read from the date input the page uses to drive its own filter, with the
     * visible "As of : YYYY-MM-DD" caption as a fallback.
     */
    private function sessionDate(Crawler $page): ?Carbon
    {
        $input = $page->filter('#fromdate, input[name="date"]');

        if ($input->count() > 0) {
            $value = trim((string) $input->first()->attr('value'));

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return Carbon::parse($value)->startOfDay();
            }
        }

        if (preg_match('/As of\s*:?\s*(\d{4}-\d{2}-\d{2})/i', $page->text(''), $matches)) {
            return Carbon::parse($matches[1])->startOfDay();
        }

        return null;
    }

    /**
     * Fallback when the page carries no date: the most recent NEPSE session.
     */
    private function assumedTradingDay(): Carbon
    {
        $date = Carbon::now(config('nepse.timezone'))->startOfDay();
        $tradingDays = (array) config('nepse.trading_days');

        for ($i = 0; $i < 7; $i++) {
            if (in_array((int) $date->dayOfWeekIso, $tradingDays, true)) {
                return $date;
            }

            $date->subDay();
        }

        return $date;
    }

    /**
     * NEPSE Index, Sensitive Index and the sector sub-indices for the current
     * session.
     *
     * Not served by the today-share-price page: that response was checked and
     * carries no index summary anywhere in its markup. The values live on a
     * separate `/live-trading` page instead, so this issues its own request —
     * still through {@see HttpFetcher}, so it shares the same per-host
     * politeness delay as everything else this source does.
     *
     * @throws ScrapeException
     */
    public function fetchIndices(?Carbon $date = null): IndexSnapshot
    {
        $url = (string) ($this->config()['live_trading_url']
            ?? 'https://www.sharesansar.com/live-trading');

        $html = $this->http->get($this->key(), $url);
        $snapshot = (new LiveIndexParser)->parse($html);

        if ($snapshot->rows === []) {
            throw ScrapeException::markupChanged($this->key(), 'live-trading page has no readable index widgets');
        }

        // This page cannot be asked for a past session — it only ever shows
        // the current one — so a caller asking for a specific date gets
        // nothing rather than indices silently mis-filed under the wrong day.
        if ($date !== null && $snapshot->tradeDate !== null && ! $snapshot->tradeDate->isSameDay($date)) {
            throw new ScrapeException(
                sprintf(
                    '[%s] live-trading indices are for %s, not the requested %s — this page only ever '
                    .'shows the current session.',
                    $this->key(),
                    $snapshot->tradeDate->toDateString(),
                    $date->toDateString(),
                ),
                ['requested' => $date->toDateString(), 'served' => $snapshot->tradeDate->toDateString()],
            );
        }

        Log::channel('scraper')->info('Parsed live indices', [
            'source' => $this->key(),
            'rows' => count($snapshot->rows),
            'session_date' => $snapshot->tradeDate?->toDateString(),
        ]);

        return $snapshot;
    }

    /**
     * Company profile: name, sector, listed shares and paid-up value.
     *
     * The financial-ratio tab of this page is loaded over AJAX, so the numbers
     * available here are limited — Merolagani is the richer fundamentals source
     * and this exists mainly to fill in company names and sectors.
     */
    public function fetchFundamentals(string $symbol, ?string $sectorHint = null): ?FundamentalRow
    {
        $template = (string) $this->config()['company_url'];
        $url = str_replace('{symbol}', strtolower($symbol), $template);

        try {
            $page = $this->http->crawl($this->key(), $url);
        } catch (ScrapeException $e) {
            Log::channel('scraper')->warning('Company page fetch failed', [
                'source' => $this->key(),
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $pairs = $this->labelValuePairs($page);

        if ($pairs === []) {
            return null;
        }

        $listedShares = Numeric::parse($pairs['listed shares'] ?? null);
        $cash = Numeric::parse($pairs['cash dividend'] ?? null);
        $bonus = Numeric::parse($pairs['bonus share'] ?? null);

        // The page splits the payout into bonus and cash but never totals it,
        // and the total is what a dividend history is usually read against.
        $total = ($cash === null && $bonus === null) ? null : (float) $cash + (float) $bonus;

        return new FundamentalRow(
            symbol: strtoupper($symbol),
            // Published as "2081/2082" under "Year"; without it every row looks
            // like it came from the same unknown period and cannot be ordered.
            fiscalPeriod: ($pairs['year'] ?? '') !== '' ? (string) $pairs['year'] : 'unknown',
            dividendPercent: $total,
            bonusPercent: $bonus,
            cashDividendPercent: $cash,
            // "Paid up" is the par value per share (Rs 100 for most NEPSE
            // scrips); "Total paid up value" is the company's paid-up capital.
            paidUpCapital: Numeric::parse($pairs['total paid up value'] ?? null),
            listedShares: $listedShares,
            sector: $pairs['sector'] ?? null,
            raw: $pairs,
        );
    }

    /**
     * Symbol => company name for every listed scrip.
     *
     * Every ShareSansar page embeds the full company list as JSON to power its
     * search box. Reading it is one request for the whole market, instead of
     * 300+ company page crawls.
     *
     * @return array<string, string>
     */
    public function fetchCompanyNames(): array
    {
        $url = (string) $this->config()['today_price_url'];

        try {
            $html = $this->http->get($this->key(), $url);
        } catch (ScrapeException $e) {
            Log::channel('scraper')->warning('Company name list fetch failed', [
                'source' => $this->key(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (! preg_match_all(
            '/\{"id":\d+,"symbol":"([^"]+)","companyname":"([^"]*)"\}/',
            $html,
            $matches,
            PREG_SET_ORDER,
        )) {
            return [];
        }

        $names = [];

        foreach ($matches as $match) {
            $symbol = $this->normalizeSymbol($match[1]);
            $name = Numeric::text(stripslashes($match[2]));

            if ($symbol !== null && $name !== '') {
                $names[$symbol] = $name;
            }
        }

        return $names;
    }

    /**
     * Flatten every two-column table row on the page into label => value.
     *
     * @return array<string, string>
     */
    private function labelValuePairs(Crawler $page): array
    {
        $pairs = [];

        $page->filter('table tr')->each(function (Crawler $row) use (&$pairs): void {
            $cells = $row->filter('td, th');

            if ($cells->count() < 2) {
                return;
            }

            $label = strtolower(Numeric::text($cells->eq(0)->text('')));
            $value = Numeric::text($cells->eq(1)->text(''));

            // Strip trailing punctuation the site uses inconsistently.
            $label = trim($label, ": \t\n\r");

            if ($label === '' || $value === '' || strlen($label) > 60) {
                return;
            }

            // Keep the first occurrence; repeated labels (e.g. "Signal") belong
            // to different widgets and the later ones are not what we want.
            if (! isset($pairs[$label])) {
                $pairs[$label] = $value;
            }
        });

        return $pairs;
    }
}
