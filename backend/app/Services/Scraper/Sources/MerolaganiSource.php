<?php

declare(strict_types=1);

namespace App\Services\Scraper\Sources;

use App\Exceptions\ScrapeException;
use App\Services\Scraper\FundamentalRow;
use App\Services\Scraper\PriceRow;
use App\Services\Scraper\PriceSnapshot;
use App\Support\Numeric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Fallback price source and primary fundamentals source.
 *
 * Two quirks drive the implementation:
 *
 * 1. The live-trading table's <thead> is mislabelled — it reads
 *    "Open | High | Low" while the cells are actually High | Low | Open.
 *    Header-name mapping would therefore silently transpose the values, so
 *    this source uses an explicit positional map and then validates each row
 *    (high >= low, high >= close >= low) before accepting it. Rows that fail
 *    the check are dropped rather than trusted.
 *
 * 2. Previous close and turnover are populated client side, so they are not
 *    available here. Left null; the persister carries the previous session's
 *    close forward instead of inventing one.
 */
final class MerolaganiSource extends AbstractHtmlSource
{
    /**
     * Verified against the equivalent, correctly-labelled StockQuote page.
     * Index is the position within the row's <td> list.
     */
    private const COLUMNS = [
        'symbol' => 0,
        'ltp' => 1,
        'change_percent' => 2,
        'high' => 3,
        'low' => 4,
        'open' => 5,
        'volume' => 6,
    ];

    public function key(): string
    {
        return 'merolagani';
    }

    public function label(): string
    {
        return (string) ($this->config()['label'] ?? 'Merolagani');
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

        $page = $this->http->crawl($this->key(), $url);
        $table = $this->requireTable($page, 'table.live-trading', 'the live trading table');

        $rows = [];
        $transposed = 0;

        foreach ($this->rows($table) as $cells) {
            $symbol = $this->normalizeSymbol($this->cell($cells, self::COLUMNS['symbol']));

            if ($symbol === null) {
                continue;
            }

            $close = $this->numberAt($cells, self::COLUMNS['ltp']);

            if ($close === null || $close <= 0) {
                continue;
            }

            $high = $this->numberAt($cells, self::COLUMNS['high']);
            $low = $this->numberAt($cells, self::COLUMNS['low']);
            $open = $this->numberAt($cells, self::COLUMNS['open']);

            // If the positional assumption ever breaks, OHLC stops being
            // internally consistent. Drop the OHLC rather than persist a
            // transposed row; the close is still trustworthy on its own.
            if (! $this->ohlcIsConsistent($open, $high, $low, $close)) {
                $transposed++;
                $open = $high = $low = null;
            }

            $rows[] = new PriceRow(
                symbol: $symbol,
                name: null,
                sector: null,
                close: $close,
                open: $open,
                high: $high,
                low: $low,
                changePercent: $this->numberAt($cells, self::COLUMNS['change_percent']),
                volume: (int) ($this->numberAt($cells, self::COLUMNS['volume']) ?? 0),
            );
        }

        if ($transposed > 0) {
            Log::channel('scraper')->warning('Dropped inconsistent OHLC rows', [
                'source' => $this->key(),
                'count' => $transposed,
                'hint' => 'the live-trading column order may have changed',
            ]);
        }

        Log::channel('scraper')->info('Parsed daily prices', [
            'source' => $this->key(),
            'rows' => count($rows),
        ]);

        // This page carries no session date, so the trading calendar is the
        // best available answer. Flagged as non-authoritative, which stops the
        // scrape service from writing it over a date the user explicitly asked
        // for.
        return new PriceSnapshot(
            tradeDate: $this->assumedTradingDay(),
            rows: $rows,
            dateIsAuthoritative: false,
        );
    }

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

    private function ohlcIsConsistent(?float $open, ?float $high, ?float $low, float $close): bool
    {
        if ($high === null || $low === null || $open === null) {
            return false;
        }

        if ($high <= 0 || $low <= 0 || $open <= 0) {
            return false;
        }

        // Allow a paisa of rounding slack.
        $epsilon = 0.011;

        return $high + $epsilon >= $low
            && $high + $epsilon >= $close
            && $close + $epsilon >= $low
            && $high + $epsilon >= $open
            && $open + $epsilon >= $low;
    }

    /**
     * Company fundamentals: EPS, P/E, book value, PBV, dividend and sector.
     *
     * These are server rendered here, which is why Merolagani is the
     * fundamentals source even though it is the fallback for prices.
     */
    public function fetchFundamentals(string $symbol, ?string $sectorHint = null): ?FundamentalRow
    {
        $template = (string) $this->config()['company_url'];
        $url = str_replace('{symbol}', strtoupper($symbol), $template);

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

        $epsRaw = $pairs['eps'] ?? null;

        return new FundamentalRow(
            symbol: strtoupper($symbol),
            // EPS is published as "28.36 (FY:082-083, Q:4)" — the parenthetical
            // is the only place the reporting period appears on the page.
            fiscalPeriod: $this->parseFiscalPeriod($epsRaw) ?? 'unknown',
            // EPS and dividend carry their reporting period inline. Numeric
            // rejects those outright — deliberately, since silently truncating
            // a malformed number is how bad data gets in — so trim the
            // annotation here where its shape is known.
            eps: Numeric::parse($this->beforeAnnotation($epsRaw)),
            peRatio: Numeric::parse($pairs['p e ratio'] ?? $pairs['pe ratio'] ?? null),
            bookValue: Numeric::parse($pairs['book value'] ?? null),
            pbRatio: Numeric::parse($pairs['pbv'] ?? null),
            dividendPercent: Numeric::parse($this->beforeAnnotation($pairs['dividend'] ?? $pairs['% dividend'] ?? null)),
            // Deliberately not mapped: merolagani's "1 year yield" is the
            // one-year *price* return, not a dividend yield. It goes negative
            // (RADHI: -10.82) which no dividend yield can, and for NABIL reads
            // 5.43 against an actual 2.25 payout. Yield is derived from the
            // cash dividend and price instead — see NepseScraperService.
            dividendYield: null,
            paidUpCapital: Numeric::parse($pairs['total paidup value'] ?? null),
            listedShares: Numeric::parse($pairs['shares outstanding'] ?? $pairs['listed shares'] ?? null),
            marketCapitalization: Numeric::parse($pairs['market capitalization'] ?? null),
            sector: $pairs['sector'] ?? null,
            raw: $pairs,
        );
    }

    /**
     * "28.36 (FY:082-083, Q:4)" => "28.36"
     */
    private function beforeAnnotation(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim(explode('(', $value, 2)[0]);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * "28.36 (FY:082-083, Q:4)" => "082-083 Q4"
     */
    private function parseFiscalPeriod(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! preg_match('/FY\s*:\s*([0-9\-\/]+)/i', $value, $fy)) {
            return null;
        }

        $period = trim($fy[1]);

        if (preg_match('/Q\s*:?\s*(\d)/i', $value, $q)) {
            $period .= ' Q'.$q[1];
        }

        return $period;
    }

    /**
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

            $label = trim($label, ": \t\n\r");
            // Normalise punctuation so "P/E Ratio" and "% Dividend" are
            // addressable with stable keys.
            $normalized = trim(preg_replace('/[^a-z0-9]+/', ' ', $label) ?? '');

            if ($normalized === '' || $value === '' || strlen($normalized) > 60) {
                return;
            }

            if (! isset($pairs[$normalized])) {
                $pairs[$normalized] = $value;
            }
        });

        return $pairs;
    }
}
