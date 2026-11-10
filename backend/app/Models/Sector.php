<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Sector extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'description'];

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    /**
     * The same sector under every spelling the sources use.
     *
     * Slugging alone is not enough: the sources disagree on more than casing and
     * spacing. Merolagani files "Hydro Power", "Hotels And Tourism", "Tradings"
     * and "Promotor Share"; ShareSansar files "Hydropower", "Hotel & Tourism",
     * "Trading" and "Promoter Share". Left unmapped, each pair became two
     * sectors, which split 103 hydropower scrips into groups of 103 and 7 — and
     * a peer percentile computed against the wrong seven is worse than no
     * percentile at all.
     *
     * The canonical spelling on the right is ShareSansar's, because that is the
     * vocabulary the quarterly-report endpoint accepts as a request parameter.
     *
     * @var array<string, string> Alias slug => canonical name.
     */
    private const ALIASES = [
        'hydro-power' => 'Hydropower',
        'hotels-and-tourism' => 'Hotel & Tourism',
        'hotel-and-tourism' => 'Hotel & Tourism',
        'tradings' => 'Trading',
        'promotor-share' => 'Promoter Share',
        'promoter-shares' => 'Promoter Share',
        'corporate-debentures' => 'Corporate Debenture',
        'commercial-banks' => 'Commercial Bank',
        'development-banks' => 'Development Bank',
        'life-insurances' => 'Life Insurance',
        'non-life-insurances' => 'Non-Life Insurance',
        'micro-finance' => 'Microfinance',
        'microfinances' => 'Microfinance',
        'mutual-funds' => 'Mutual Fund',
        'manufacturing-and-processings' => 'Manufacturing and Processing',
        'investments' => 'Investment',
        'finances' => 'Finance',
        'others-sector' => 'Others',
    ];

    /**
     * Sector names arrive from the scraped tables with inconsistent casing,
     * spacing and wording, so canonicalise before resolve-or-create.
     */
    public static function resolve(?string $name): ?self
    {
        $name = self::decodeName((string) $name);

        if ($name === '') {
            return null;
        }

        $canonical = self::ALIASES[Str::slug($name)] ?? null;

        if ($canonical !== null) {
            $name = $canonical;
        }

        return static::firstOrCreate(
            ['slug' => Str::slug($name)],
            // A canonical name is stored verbatim. Str::title would mangle it:
            // it renders "Non-Life Insurance" as "Non-life Insurance" and
            // "Manufacturing and Processing" as "... And Processing", neither of
            // which the quarterly-report endpoint accepts as a sector.
            ['name' => $canonical ?? Str::title($name)],
        );
    }

    /**
     * Canonical name for a scraped sector string, without touching the database.
     */
    /**
     * Undo the HTML encoding the sources leave in a sector name.
     *
     * Merolagani files "Hotel &amp; Tourism" with the entity intact. No alias
     * matched its slug (`hotel-amp-tourism`), so it fell through to
     * Str::title() below — which title-cases every word and turns "&amp;" into
     * "&Amp;". That is not a valid entity, so it can never decode again, and
     * the result was a second "Hotel &Amp; Tourism" row splitting the sector's
     * scrips across two populations exactly as the hydropower split did.
     *
     * Decoding is therefore case-insensitive on "&amp;" (so an
     * already-mangled stored name repairs too) and repeated, because these
     * pages are sometimes double-encoded.
     */
    private static function decodeName(string $name): string
    {
        for ($i = 0; $i < 3; $i++) {
            $decoded = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $decoded = (string) preg_replace('/&amp;/i', '&', $decoded);

            if ($decoded === $name) {
                break;
            }

            $name = $decoded;
        }

        return trim($name);
    }

    public static function canonicalName(?string $name): ?string
    {
        $name = self::decodeName((string) $name);

        if ($name === '') {
            return null;
        }

        return self::ALIASES[Str::slug($name)] ?? $name;
    }
}
