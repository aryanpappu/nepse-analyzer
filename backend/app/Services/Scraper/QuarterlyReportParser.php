<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Support\Numeric;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Turns ShareSansar's quarterly-report fragment into a {@see QuarterlyReport}.
 *
 * The hard part is that there is no single template. Each regulator-defined
 * sector files on its own schedule with its own wording, so the same concept
 * arrives under many labels:
 *
 *   share capital  -> "Share capital" | "Paid Up Capital" | "Equity Share Capital"
 *   book value     -> "Net Worth Per Share" | "Networth Per Share"
 *                     | "Net Worth Per share (Rs.)" | "Networth per share (Rs.)"
 *   EPS            -> "Basic Earnings Per Share(Annualized EPS)"
 *                     | "Earnings Per Share (EPS Annualized -Rs.)"
 *                     | "Annualized Basic Earnings Per Share (Rs.)"
 *
 * Matching on exact strings would therefore need a list that silently rots every
 * time a template is reworded. Instead each metric declares phrases that must
 * appear (and phrases that must not) once the label is normalised, which is both
 * tolerant of rewording and — because of the exclusions — precise about the
 * near-misses that actually cause damage: "Profit before income tax" is not net
 * profit, and "Total Equity & Liabilities" is not total equity.
 */
final class QuarterlyReportParser
{
    /**
     * Metric rules, evaluated against every normalised label in the fragment.
     *
     *   all  — every phrase must appear
     *   any  — at least one phrase must appear (skipped when empty)
     *   none — no phrase may appear
     *
     * Phrases are matched on word boundaries against the normalised label, so
     * 'asset' does not match 'assets'; plurals are spelled out where they occur.
     *
     * @var array<string, array{all?: list<string>, any?: list<string>, none?: list<string>}>
     */
    private const RULES = [
        // --- Balance sheet -------------------------------------------------
        'shareCapital' => [
            'any' => ['share capital', 'paid up capital', 'paidup capital'],
            // "Share premium" is a separate line; "Total Share Capital and
            // Reserves" would double-count reserves into capital.
            'none' => ['premium', 'reserve', 'proposed'],
        ],
        'sharePremium' => [
            'all' => ['share premium'],
        ],
        'retainedEarnings' => [
            'all' => ['retained'],
            'any' => ['earnings', 'earning', 'profit'],
        ],
        'reserves' => [
            'any' => ['reserves', 'reserve and surplus', 'reserve surplus', 'other reserves'],
            'none' => ['revaluation'],
        ],
        'totalEquity' => [
            'any' => ['total equity', 'total equity fund'],
            // "Total liabilities and equity" and "Total Equity & Liabilities"
            // are the balance-sheet total, an order of magnitude larger.
            'none' => ['liabilities', 'liability'],
        ],
        'totalAssets' => [
            'all' => ['total assets'],
            'none' => ['current', 'non'],
        ],

        // --- Income statement ----------------------------------------------
        'netProfit' => [
            'any' => [
                'net profit',
                'profit for the period',
                'profit for the year',
                'profit loss for the period',
                'profit loss for the year',
            ],
            'none' => [
                'before', 'gross', 'margin', 'comprehensive', 'operating',
                'associates', 'associate', 'share of', 'bonus', 'premium',
            ],
        ],

        // --- Per-share and ratio table --------------------------------------
        'outstandingShares' => [
            'all' => ['outstanding shares'],
        ],
        'bookValue' => [
            'any' => ['net worth per share', 'networth per share'],
        ],
        'eps' => [
            'any' => ['earnings per share', 'earning per share'],
            // Diluted EPS sits directly beneath basic EPS and is usually equal;
            // taking whichever matched last would flip between them run to run.
            'none' => ['diluted'],
        ],
        'peRatio' => [
            'any' => ['p e ratio', 'price to earning ratio', 'price to earnings ratio'],
        ],
        'pbRatio' => [
            'any' => [
                'price to book', 'p b ratio', 'pb ratio',
                'price to book value ratio',
            ],
        ],
        'roe' => [
            'all' => ['return on'],
            'any' => ['equity'],
        ],
        'roa' => [
            'all' => ['return on'],
            'any' => ['assets', 'asset'],
            // "Return on Invested Capital" is neither, and shares the prefix.
            'none' => ['invested'],
        ],

        // --- Banking-only prudential ratios ----------------------------------
        'nplPercent' => [
            'all' => ['npl'],
            'any' => ['non performing loan', 'total loan'],
            'none' => ['provision'],
        ],
        'capitalAdequacyRatio' => [
            'any' => ['capital fund to rwa', 'capital adequacy'],
            // Tier I / CET I are components, not the headline ratio.
            'none' => ['tier', 'cet'],
        ],
        'baseRate' => [
            'all' => ['base rate'],
        ],
    ];

    /**
     * Balance-sheet and income-statement tables are headed "Particulars (Rs. in
     * '000)"; the ratio tables are headed plain "Particulars". Scaling by the
     * table header rather than by metric name means a monetary line we have not
     * mapped yet still lands in raw at the right magnitude.
     */
    private const THOUSANDS_PATTERN = "/in\s*'?\s*000/i";

    public function parse(string $symbol, string $html): QuarterlyReport
    {
        $crawler = new Crawler($html);

        $values = [];
        $raw = [];
        $period = null;

        foreach ($crawler->filter('table')->each(fn (Crawler $t): Crawler => $t) as $table) {
            $rows = $this->rows($table);

            if ($rows === []) {
                continue;
            }

            // The first row is the header: label column, then one column per
            // reported period with the most recent first.
            $header = array_shift($rows);
            $scale = preg_match(self::THOUSANDS_PATTERN, $header[0] ?? '') === 1 ? 1000.0 : 1.0;
            $period ??= $this->parsePeriod($header[1] ?? '');

            foreach ($rows as $cells) {
                $label = trim($cells[0] ?? '');
                $value = $cells[1] ?? '';

                if ($label === '' || $value === '') {
                    continue;
                }

                $normalized = $this->normalize($label);

                if ($normalized === '' || strlen($normalized) > 120) {
                    continue;
                }

                $raw[$normalized] ??= $value;

                $number = Numeric::parse($value);

                if ($number === null) {
                    continue;
                }

                foreach ($this->metricsFor($normalized) as $metric) {
                    // First occurrence wins. These reports repeat a handful of
                    // labels across the statement and the ratio table (P/E shows
                    // up in both for banks), and the earlier table is the
                    // detailed one.
                    $values[$metric] ??= $number * ($this->isMonetary($metric) ? $scale : 1.0);
                }
            }
        }

        return new QuarterlyReport(
            symbol: strtoupper($symbol),
            fiscalPeriod: $period,
            shareCapital: $values['shareCapital'] ?? null,
            sharePremium: $values['sharePremium'] ?? null,
            retainedEarnings: $values['retainedEarnings'] ?? null,
            reserves: $values['reserves'] ?? null,
            totalEquity: $values['totalEquity'] ?? null,
            netProfit: $values['netProfit'] ?? null,
            totalAssets: $values['totalAssets'] ?? null,
            // Published as a plain count, never in thousands.
            outstandingShares: $values['outstandingShares'] ?? null,
            bookValue: $values['bookValue'] ?? null,
            eps: $values['eps'] ?? null,
            peRatio: $values['peRatio'] ?? null,
            pbRatio: $values['pbRatio'] ?? null,
            roe: $values['roe'] ?? null,
            roa: $values['roa'] ?? null,
            nplPercent: $values['nplPercent'] ?? null,
            capitalAdequacyRatio: $values['capitalAdequacyRatio'] ?? null,
            baseRate: $values['baseRate'] ?? null,
            raw: $raw,
        );
    }

    /**
     * Metrics whose published figure is in thousands of rupees and therefore
     * needs the table's multiplier. Everything else is a count, a ratio or a
     * per-share figure that is already absolute.
     *
     * @var list<string>
     */
    private const MONETARY = [
        'shareCapital', 'sharePremium', 'retainedEarnings', 'reserves',
        'totalEquity', 'netProfit', 'totalAssets',
    ];

    private function isMonetary(string $metric): bool
    {
        return in_array($metric, self::MONETARY, true);
    }

    /**
     * @return list<string>
     */
    private function metricsFor(string $normalized): array
    {
        $padded = ' '.$normalized.' ';
        $matched = [];

        foreach (self::RULES as $metric => $rule) {
            if (! $this->matches($padded, $rule)) {
                continue;
            }

            $matched[] = $metric;
        }

        return $matched;
    }

    /**
     * @param  array{all?: list<string>, any?: list<string>, none?: list<string>}  $rule
     */
    private function matches(string $padded, array $rule): bool
    {
        foreach ($rule['none'] ?? [] as $phrase) {
            if (str_contains($padded, ' '.$phrase.' ')) {
                return false;
            }
        }

        foreach ($rule['all'] ?? [] as $phrase) {
            if (! str_contains($padded, ' '.$phrase.' ')) {
                return false;
            }
        }

        $any = $rule['any'] ?? [];

        if ($any === []) {
            return true;
        }

        foreach ($any as $phrase) {
            if (str_contains($padded, ' '.$phrase.' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Net Profit/(Loss) For the Period" => "net profit loss for the period"
     *
     * Punctuation carries no meaning in these labels — the same concept appears
     * as "Reserve & Surplus", "Reserve and Surplus" and "Reserves & Surplus" —
     * so it is flattened to single spaces and '&' is spelled out first.
     */
    private function normalize(string $label): string
    {
        $label = str_replace('&', ' and ', mb_strtolower($label));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $label) ?? '');
    }

    /**
     * "4th Quarter 2082/2083" => "2082/2083 Q4"
     *
     * Canonicalised because fiscal_period is half the upsert key on
     * stock_fundamentals: two spellings of the same quarter would insert two
     * rows and the newer figures would never overwrite the older ones.
     */
    private function parsePeriod(string $header): ?string
    {
        if (preg_match('/(\d{4})\s*[\/\-]\s*(\d{2,4})/', $header, $fy) !== 1) {
            return null;
        }

        $period = $fy[1].'/'.$fy[2];

        if (preg_match('/(\d)\s*(?:st|nd|rd|th)?\s*quarter/i', $header, $q) === 1) {
            $period .= ' Q'.$q[1];
        }

        return $period;
    }

    /**
     * @return list<list<string>>
     */
    private function rows(Crawler $table): array
    {
        return $table->filter('tr')->each(
            fn (Crawler $row): array => $row->filter('td, th')->each(
                fn (Crawler $cell): string => Numeric::text($cell->text('')),
            ),
        );
    }
}
