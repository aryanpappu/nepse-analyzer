<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Models\Stock;

/**
 * Decides what kind of instrument a scrip is, which decides how it gets
 * analysed: an equity goes through the technical + fundamental pipeline, a
 * closed-end fund through the NAV pipeline, and debentures are skipped.
 *
 * Getting this wrong is not cosmetic. Running RSI and MACD over a fund unit
 * that trades in a four-paisa band produces confident nonsense, and analysing a
 * debenture as if it were a growth stock is meaningless.
 *
 * The decision is a strict if/else chain, strongest evidence first, and it
 * refuses to guess: when nothing is conclusive the answer is UNKNOWN and the
 * scrip is queued for a human to set manually, rather than being quietly
 * defaulted to equity and analysed with the wrong model.
 *
 *   1. manual     — a human set it. Never overridden.
 *   2. nav_probe  — the scrip publishes a daily NAV. Only funds do, so this is
 *                   definitive. Note the converse does NOT hold: a matured or
 *                   closed fund stops publishing NAV, so "no NAV" is not
 *                   evidence of being an equity.
 *   3. sector     — the exchange's own classification, from the company crawl.
 *   4. name       — reliable, with fund *management* companies excluded.
 *   5. symbol     — ticker shape. Weak, and only trusted for the patterns that
 *                   are genuinely unambiguous on NEPSE.
 *   6. unknown    — nothing was conclusive. Needs manual review.
 */
final class InstrumentClassifier
{
    public const EQUITY = 'equity';

    public const MUTUAL_FUND = 'mutual_fund';

    public const DEBENTURE = 'debenture';

    public const PROMOTER = 'promoter';

    public const PREFERENCE = 'preference';

    /** Nothing was conclusive — do not analyse until a human decides. */
    public const UNKNOWN = 'unknown';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_NAV = 'nav_probe';

    public const SOURCE_SECTOR = 'sector';

    public const SOURCE_NAME = 'name';

    public const SOURCE_SYMBOL = 'symbol';

    public const SOURCE_UNRESOLVED = 'unresolved';

    /** Sector name (normalised) => instrument type. */
    private const SECTOR_MAP = [
        'mutual fund' => self::MUTUAL_FUND,
        'corporate debentures' => self::DEBENTURE,
        'government bonds' => self::DEBENTURE,
        'promoter share' => self::PROMOTER,
        'preference share' => self::PREFERENCE,
    ];

    /**
     * Classify a stock.
     *
     * @param  bool|null  $publishesNav  Result of a NAV probe. Null = not checked.
     * @return array{type: string, source: string, reason: string}
     */
    public function classify(Stock $stock, ?bool $publishesNav = null): array
    {
        // 1. A human already decided. Leave it alone.
        if ($stock->classification_source === self::SOURCE_MANUAL) {
            return [
                'type' => $stock->instrument_type ?? self::UNKNOWN,
                'source' => self::SOURCE_MANUAL,
                'reason' => 'set manually; automatic classification does not override this',
            ];
        }

        // 2. A published NAV is conclusive proof of a fund.
        $publishesNav ??= $stock->publishes_nav;

        if ($publishesNav === true) {
            return [
                'type' => self::MUTUAL_FUND,
                'source' => self::SOURCE_NAV,
                'reason' => 'publishes a daily NAV, which only funds do',
            ];
        }

        // 3. The exchange's own sector classification.
        $sector = $this->normalize($stock->sector?->name);

        if ($sector !== '' && isset(self::SECTOR_MAP[$sector])) {
            return [
                'type' => self::SECTOR_MAP[$sector],
                'source' => self::SOURCE_SECTOR,
                'reason' => "listed under the \"{$stock->sector?->name}\" sector",
            ];
        }

        // 4. The company name.
        $byName = $this->fromName($stock->name);

        if ($byName !== null) {
            return [
                'type' => $byName,
                'source' => self::SOURCE_NAME,
                'reason' => 'inferred from the company name',
            ];
        }

        // 5. Ticker shape, only where NEPSE conventions are unambiguous.
        $bySymbol = $this->fromSymbol($stock->symbol);

        if ($bySymbol !== null) {
            return [
                'type' => $bySymbol,
                'source' => self::SOURCE_SYMBOL,
                'reason' => 'inferred from the ticker pattern',
            ];
        }

        // 6. Nothing conclusive.
        return [
            'type' => self::UNKNOWN,
            'source' => self::SOURCE_UNRESOLVED,
            'reason' => 'no sector, no usable company name and no recognised ticker pattern — needs manual review',
        ];
    }

    /**
     * Name-based classification, or null when the name says nothing useful.
     */
    public function fromName(?string $name): ?string
    {
        $clean = trim((string) $name);

        if ($clean === '') {
            return null;
        }

        // Names frequently end in a disambiguating parenthetical — "Bottlers
        // Nepal Limited (Balaju)", "Himalayan Laghubitta ... (Former Civil
        // Laghubitta)". Strip it so the corporate-suffix test below still sees
        // the real end of the name.
        $clean = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $clean) ?? $clean);

        if ($clean === '') {
            return null;
        }

        if (preg_match('/\bdebenture\b|\bbond\b/i', $clean)) {
            return self::DEBENTURE;
        }

        if (preg_match('/\bpromoter\b/i', $clean)) {
            return self::PROMOTER;
        }

        if (preg_match('/\bpreference\b/i', $clean)) {
            return self::PREFERENCE;
        }

        // Many NEPSE funds are named in Nepali rather than English:
        //   "Yojana" = scheme   (Kumari Sabal Yojana, Garima Samriddhi Yojana)
        //   "Kosh"   = fund     (Laxmi Unnati Kosh)
        // Matching only the English words would leave a large share of the
        // fund universe unclassified, which is how they showed up as unknown.
        if (preg_match('/\bfund\b|\bscheme\b|\byojana\b|\bkosh\b/i', $clean)) {
            // "Aadhyanta Fund Management Limited" runs funds, it is not one. A
            // closed-end fund unit is named "<Something> Fund" or
            // "<Something> Scheme", optionally numbered, with no corporate
            // suffix — so a corporate suffix rules the fund reading out.
            if (preg_match('/\b(management|manager|managers|capital|merchant|advisor|advisors|securities)\b/i', $clean)) {
                return self::EQUITY;
            }

            if (preg_match('/\b(limited|ltd|company|co)\.?\s*$/i', $clean)) {
                return self::EQUITY;
            }

            return self::MUTUAL_FUND;
        }

        // A corporate suffix with none of the markers above is positive evidence
        // of an ordinary listed company, not merely an absence of evidence.
        if (preg_match('/\b(limited|ltd|company)\.?\s*$/i', $clean)) {
            return self::EQUITY;
        }

        return null;
    }

    /**
     * Ticker-shape classification, or null when the ticker is not conclusive.
     *
     * Returns null rather than defaulting to equity: a ticker that matches
     * nothing is exactly the case that should go to manual review.
     */
    public function fromSymbol(string $symbol): ?string
    {
        $symbol = strtoupper(trim($symbol));

        if ($symbol === '') {
            return null;
        }

        // Debentures issued with a coupon rate prefix, e.g. "1025BOKL86".
        if (preg_match('/^\d/', $symbol)) {
            return self::DEBENTURE;
        }

        // The common NEPSE debenture shape: issuer code, "D", then the Bikram
        // Sambat maturity year — "GWFD83", "CBLD88", "ICFCD89", "RBBD2088".
        if (preg_match('/D\d{2,4}$/', $symbol)) {
            return self::DEBENTURE;
        }

        if (str_ends_with($symbol, 'PO')) {
            return self::PROMOTER;
        }

        // Fund tickers overwhelmingly end in MF, or in F with an optional scheme
        // number: NIBLSF, NICADF, NFCF, GIBF1, LVF2, MMF1, CMF2, RBBF40.
        if (preg_match('/(MF|F)\d{0,2}$/', $symbol)) {
            return self::MUTUAL_FUND;
        }

        return null;
    }

    /**
     * Whether a type gets the NAV pipeline instead of the technical one.
     */
    public function usesNav(string $instrumentType): bool
    {
        return $instrumentType === self::MUTUAL_FUND;
    }

    /**
     * Whether a type is worth spending AI analysis on.
     *
     * Debentures and preference shares have fixed coupons, and an UNKNOWN scrip
     * would be analysed with the wrong model, so all are excluded.
     */
    public function isAnalysable(string $instrumentType): bool
    {
        return in_array($instrumentType, [self::EQUITY, self::MUTUAL_FUND], true);
    }

    /** @return list<string> */
    public static function types(): array
    {
        return [
            self::EQUITY,
            self::MUTUAL_FUND,
            self::DEBENTURE,
            self::PROMOTER,
            self::PREFERENCE,
            self::UNKNOWN,
        ];
    }

    private function normalize(?string $value): string
    {
        $clean = strtolower(trim((string) $value));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $clean) ?? '');
    }
}
