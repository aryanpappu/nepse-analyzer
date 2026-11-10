<?php

declare(strict_types=1);

namespace App\Services\Scraper;

/**
 * Fundamentals crawled from a company detail page.
 *
 * Every field is nullable: these pages publish different metric sets per sector
 * (a bank shows NPL and capital adequacy, a hydropower company does not), and
 * absent is meaningfully different from zero.
 */
final readonly class FundamentalRow
{
    /**
     * @param  array<string, string>  $raw  Every label/value pair scraped, kept
     *                                      verbatim so a mapping gap is
     *                                      recoverable without re-crawling.
     */
    public function __construct(
        public string $symbol,
        public string $fiscalPeriod = 'unknown',
        public ?string $reportedOn = null,
        public ?float $eps = null,
        public ?float $peRatio = null,
        public ?float $bookValue = null,
        public ?float $pbRatio = null,
        public ?float $dividendPercent = null,
        public ?float $bonusPercent = null,
        public ?float $cashDividendPercent = null,
        public ?float $dividendYield = null,
        public ?float $roe = null,
        public ?float $roa = null,
        public ?float $netProfit = null,
        public ?float $netWorth = null,
        public ?float $paidUpCapital = null,
        public ?float $reserve = null,
        public ?float $nplPercent = null,
        public ?float $capitalAdequacyRatio = null,
        public ?float $baseRate = null,
        public ?float $listedShares = null,
        public ?float $marketCapitalization = null,
        public ?string $sector = null,
        // --- Balance-sheet figures, from the quarterly report -----------------
        // Share capital is *not* the same number as paid-up capital: it is the
        // balance-sheet line, which includes capital awaiting allotment. NABIL
        // reports 32,057M share capital against 27,057M paid up, and the
        // published bank comparisons quote the former.
        public ?float $shareCapital = null,
        public ?float $retainedEarnings = null,
        public ?float $totalEquity = null,
        public ?float $totalAssets = null,
        public ?float $outstandingShares = null,
        public ?float $distributableEps = null,
        public array $raw = [],
    ) {}

    /**
     * Fill this row's gaps from another source's row.
     *
     * The sources are complementary rather than redundant: merolagani carries
     * EPS, P/E, book value and P/B; sharesansar is the only one that splits the
     * payout into bonus and cash. Taking whichever answered first threw half
     * the picture away, so every field falls back independently.
     *
     * $this wins on conflict; $other only fills nulls.
     */
    public function mergeWith(self $other): self
    {
        return new self(
            symbol: $this->symbol,
            fiscalPeriod: $this->fiscalPeriod !== 'unknown' ? $this->fiscalPeriod : $other->fiscalPeriod,
            reportedOn: $this->reportedOn ?? $other->reportedOn,
            eps: $this->eps ?? $other->eps,
            peRatio: $this->peRatio ?? $other->peRatio,
            bookValue: $this->bookValue ?? $other->bookValue,
            pbRatio: $this->pbRatio ?? $other->pbRatio,
            dividendPercent: $this->dividendPercent ?? $other->dividendPercent,
            bonusPercent: $this->bonusPercent ?? $other->bonusPercent,
            cashDividendPercent: $this->cashDividendPercent ?? $other->cashDividendPercent,
            dividendYield: $this->dividendYield ?? $other->dividendYield,
            roe: $this->roe ?? $other->roe,
            roa: $this->roa ?? $other->roa,
            netProfit: $this->netProfit ?? $other->netProfit,
            netWorth: $this->netWorth ?? $other->netWorth,
            paidUpCapital: $this->paidUpCapital ?? $other->paidUpCapital,
            reserve: $this->reserve ?? $other->reserve,
            nplPercent: $this->nplPercent ?? $other->nplPercent,
            capitalAdequacyRatio: $this->capitalAdequacyRatio ?? $other->capitalAdequacyRatio,
            baseRate: $this->baseRate ?? $other->baseRate,
            listedShares: $this->listedShares ?? $other->listedShares,
            marketCapitalization: $this->marketCapitalization ?? $other->marketCapitalization,
            sector: $this->sector ?? $other->sector,
            shareCapital: $this->shareCapital ?? $other->shareCapital,
            retainedEarnings: $this->retainedEarnings ?? $other->retainedEarnings,
            totalEquity: $this->totalEquity ?? $other->totalEquity,
            totalAssets: $this->totalAssets ?? $other->totalAssets,
            outstandingShares: $this->outstandingShares ?? $other->outstandingShares,
            distributableEps: $this->distributableEps ?? $other->distributableEps,
            // Keep both label sets; a mapping gap stays recoverable without
            // re-crawling, which is the whole point of holding raw.
            raw: $this->raw + $other->raw,
        );
    }

    /**
     * True when the crawl found nothing worth persisting — used to avoid
     * writing empty rows for scrips whose page failed to render.
     */
    public function isEmpty(): bool
    {
        foreach ([
            $this->eps, $this->peRatio, $this->bookValue, $this->pbRatio,
            $this->dividendPercent, $this->roe, $this->netProfit,
            $this->paidUpCapital, $this->marketCapitalization,
            // Sharesansar publishes the dividend split as bonus + cash rather
            // than a single "dividend" figure, and is the only source that
            // renders any of this server-side. Omitting these three discarded
            // every row it produced, which is why the table stayed empty while
            // the crawl reported success.
            $this->cashDividendPercent, $this->bonusPercent, $this->listedShares,
            // The quarterly-report source produces none of the above — it is a
            // balance sheet, not a market page — so without these a full,
            // correctly parsed report would be discarded as empty.
            $this->shareCapital, $this->retainedEarnings, $this->totalEquity,
            $this->totalAssets, $this->distributableEps,
        ] as $value) {
            if ($value !== null) {
                return false;
            }
        }

        return true;
    }
}
