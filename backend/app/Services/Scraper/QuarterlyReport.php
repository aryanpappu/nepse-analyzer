<?php

declare(strict_types=1);

namespace App\Services\Scraper;

/**
 * One company's latest published quarterly report, normalised across sectors.
 *
 * NEPSE issuers file on sector-specific templates: a bank reports "Share
 * capital" and "Profit for the period", a hydropower company reports "Paid Up
 * Capital" and "Net Profit", a manufacturer reports "Equity Share Capital" and
 * "Net Profit/(Loss) For the Period". This DTO is the shape they all collapse
 * to, so nothing downstream has to know which template produced it.
 *
 * Monetary fields are in NPR (the source publishes them in thousands; the
 * parser applies the multiplier). Ratio fields are percentages or multiples
 * exactly as published.
 */
final readonly class QuarterlyReport
{
    /**
     * @param  string|null  $fiscalPeriod  Canonical "2082/2083 Q4".
     * @param  array<string, string>  $raw  Every label/value pair parsed, kept
     *                                      verbatim so a mapping gap is
     *                                      recoverable without re-crawling.
     */
    public function __construct(
        public string $symbol,
        public ?string $fiscalPeriod = null,
        public ?float $shareCapital = null,
        public ?float $sharePremium = null,
        public ?float $retainedEarnings = null,
        public ?float $reserves = null,
        public ?float $totalEquity = null,
        public ?float $netProfit = null,
        public ?float $totalAssets = null,
        public ?float $outstandingShares = null,
        public ?float $bookValue = null,
        public ?float $eps = null,
        public ?float $peRatio = null,
        public ?float $pbRatio = null,
        public ?float $roe = null,
        public ?float $roa = null,
        public ?float $nplPercent = null,
        public ?float $capitalAdequacyRatio = null,
        public ?float $baseRate = null,
        public array $raw = [],
    ) {}

    /**
     * Shares in issue, falling back to capital / par value.
     *
     * Only the banking template publishes a share count outright. Every other
     * template gives share capital in rupees, which divided by the par value
     * (NPR 100 for every NEPSE-listed ordinary share) is the same number — and
     * the divisor has to be the *company's* par value rather than a hardcoded
     * 100, because a handful of older scrips still carry NPR 10 shares.
     */
    public function sharesInIssue(?float $parValue = null): ?float
    {
        if ($this->outstandingShares !== null && $this->outstandingShares > 0) {
            return $this->outstandingShares;
        }

        $par = $parValue !== null && $parValue > 0 ? $parValue : 100.0;

        if ($this->shareCapital === null || $this->shareCapital <= 0) {
            return null;
        }

        return $this->shareCapital / $par;
    }

    /**
     * Distributable earnings per share — the "DEPS" every NEPSE investor quotes.
     *
     * Retained earnings are the pool a company may actually pay out as dividend;
     * per share, that is the ceiling on the next payout. It is deliberately not
     * the same as EPS, which is one period's profit and says nothing about what
     * has accumulated as distributable.
     *
     * Verified against published comparisons: NABIL retained earnings of NPR
     * 5,169,148k over 270,569,970 shares gives 19.10, the figure the market
     * quotes.
     */
    public function distributableEps(?float $parValue = null): ?float
    {
        $shares = $this->sharesInIssue($parValue);

        if ($this->retainedEarnings === null || $shares === null || $shares <= 0) {
            return null;
        }

        return round($this->retainedEarnings / $shares, 4);
    }

    /**
     * True when the crawl found nothing worth persisting.
     */
    public function isEmpty(): bool
    {
        foreach ([
            $this->shareCapital, $this->retainedEarnings, $this->reserves,
            $this->totalEquity, $this->netProfit, $this->totalAssets,
            $this->bookValue, $this->eps, $this->peRatio, $this->pbRatio,
            $this->roe, $this->roa,
        ] as $value) {
            if ($value !== null) {
                return false;
            }
        }

        return true;
    }
}
