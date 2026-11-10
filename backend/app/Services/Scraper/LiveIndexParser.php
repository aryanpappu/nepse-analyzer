<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Services\Scraper\Sources\SharesansarIndexHistorySource;
use App\Support\Numeric;
use Illuminate\Support\Carbon;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Turns ShareSansar's `/live-trading` index widget into an {@see IndexSnapshot}.
 *
 * That page — not the `today-share-price` table `SharesansarSource` already
 * fetches for prices — is where the NEPSE Index, the Sensitive Index and the
 * sector sub-indices actually live. It was verified against the live page: no
 * index summary appears anywhere in the today-share-price response, so
 * capturing indices costs one additional (and, being a separate page,
 * separately throttled) request per run rather than reusing that one.
 *
 * The widget is small on purpose: each `.mu-list` block carries only a name,
 * a turnover figure and a value + percent change — no absolute change amount,
 * no volume, transactions, advances, declines or unchanged count. Those
 * IndexRow fields stay null here; the historical feed
 * ({@see SharesansarIndexHistorySource}) adds
 * the change amount but not the rest either — ShareSansar simply does not
 * publish an index-level breadth count anywhere crawlable.
 *
 * Pure and DB-free by design, like {@see QuarterlyReportParser}: parsing logic
 * belongs in a class that can be asserted against captured markup without a
 * network call or a database.
 */
final class LiveIndexParser
{
    public function parse(string $html): IndexSnapshot
    {
        $page = new Crawler($html);
        $rows = [];

        $page->filter('.mu-list')->each(function (Crawler $widget) use (&$rows): void {
            $row = $this->parseWidget($widget);

            if ($row !== null) {
                // First occurrence wins on a duplicate name rather than the
                // last, matching AbstractHtmlSource::headerMap()'s convention.
                $rows[$row->name] ??= $row;
            }
        });

        $date = $this->sessionDate($page);

        return new IndexSnapshot(
            tradeDate: $date,
            rows: array_values($rows),
            dateIsAuthoritative: $date !== null,
        );
    }

    private function parseWidget(Crawler $widget): ?IndexRow
    {
        $heading = $widget->filter('h4');

        if ($heading->count() === 0) {
            return null;
        }

        // ShareSansar truncates long labels in this widget (e.g. "Sensitive
        // Float Inde." for "Sensitive Float Index"); recover the untruncated
        // name so the same index always slugs identically across sources.
        $name = IndexCatalog::canonicalize($heading->first()->text(''));

        if ($name === null) {
            return null;
        }

        $value = $this->numberOf($widget, '.mu-value');

        if ($value === null) {
            return null;
        }

        return new IndexRow(
            name: $name,
            value: $value,
            changePercent: $this->numberOf($widget, '.mu-percent'),
            totalTurnover: $this->numberOf($widget, '.mu-price'),
        );
    }

    private function numberOf(Crawler $widget, string $selector): ?float
    {
        $node = $widget->filter($selector);

        return $node->count() === 0 ? null : Numeric::parse($node->first()->text(''));
    }

    /**
     * The session the widget is showing, from the visible "As of :
     * YYYY-MM-DD HH:MM:SS" caption — carrying a time as well as a date, since
     * this page updates live while the market is open, unlike the daily price
     * table which only ever shows a completed session.
     */
    private function sessionDate(Crawler $page): ?Carbon
    {
        $node = $page->filter('#dDate');

        if ($node->count() === 0) {
            return null;
        }

        $text = Numeric::text($node->first()->text(''));

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $text, $matches)) {
            return Carbon::parse($matches[1])->startOfDay();
        }

        return null;
    }
}
