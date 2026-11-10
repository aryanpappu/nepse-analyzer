<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Sector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * canonicalName() is the pure half of Sector::resolve() and is what stops two
 * sources' spellings from becoming two sectors — which silently splits the peer
 * population every sector percentile is computed against.
 */
final class SectorCanonicalNameTest extends TestCase
{
    #[Test]
    #[DataProvider('aliases')]
    public function it_maps_each_sources_spelling_to_one_canonical_sector(string $scraped, string $expected): void
    {
        $this->assertSame($expected, Sector::canonicalName($scraped));
    }

    public static function aliases(): array
    {
        return [
            // Merolagani's spelling on the left, ShareSansar's on the right.
            // ShareSansar wins because its wording is also the request
            // parameter the quarterly-report endpoint accepts.
            'hydro power' => ['Hydro Power', 'Hydropower'],
            'hotels and tourism' => ['Hotels And Tourism', 'Hotel & Tourism'],
            'tradings' => ['Tradings', 'Trading'],
            'promotor share' => ['Promotor Share', 'Promoter Share'],
            'corporate debentures' => ['Corporate Debentures', 'Corporate Debenture'],
            'commercial banks' => ['Commercial Banks', 'Commercial Bank'],

            // Casing and spacing differences resolve through the same slug.
            'lowercase' => ['hydro power', 'Hydropower'],
            'extra spacing' => ['  Hydro   Power  ', 'Hydropower'],
        ];
    }

    #[Test]
    public function it_leaves_a_canonical_name_untouched(): void
    {
        $this->assertSame('Hydropower', Sector::canonicalName('Hydropower'));
        $this->assertSame('Non-Life Insurance', Sector::canonicalName('Non-Life Insurance'));
        $this->assertSame('Manufacturing and Processing', Sector::canonicalName('Manufacturing and Processing'));
    }

    #[Test]
    public function it_leaves_an_unrecognised_sector_alone_rather_than_guessing(): void
    {
        $this->assertSame('Blockchain Mining', Sector::canonicalName('Blockchain Mining'));
    }

    /**
     * The bug that split "Hotel & Tourism" into two sector rows.
     *
     * Merolagani files the entity intact; no alias matched its slug, so
     * Str::title() rendered "&amp;" as "&Amp;" — which is not a valid entity
     * and so could never decode back. All four spellings must land on one row,
     * or one scrip's peer percentiles are drawn from a population of one.
     */
    #[Test]
    public function html_encoded_ampersands_collapse_to_one_sector(): void
    {
        $expected = 'Hotel & Tourism';

        $this->assertSame($expected, Sector::canonicalName('Hotel & Tourism'));
        $this->assertSame($expected, Sector::canonicalName('Hotel &amp; Tourism'));
        // Already mangled by Str::title() and stored that way.
        $this->assertSame($expected, Sector::canonicalName('Hotel &Amp; Tourism'));
        // These pages are sometimes double-encoded.
        $this->assertSame($expected, Sector::canonicalName('Hotel &amp;amp; Tourism'));
    }

    #[Test]
    public function decoding_does_not_disturb_a_name_with_no_encoding(): void
    {
        $this->assertSame('Non-Life Insurance', Sector::canonicalName('Non-Life Insurance'));
        $this->assertSame('Manufacturing and Processing', Sector::canonicalName('Manufacturing and Processing'));
    }

    #[Test]
    public function an_empty_sector_is_null_not_an_empty_string(): void
    {
        $this->assertNull(Sector::canonicalName(null));
        $this->assertNull(Sector::canonicalName(''));
        $this->assertNull(Sector::canonicalName('   '));
    }
}
