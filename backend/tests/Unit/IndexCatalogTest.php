<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scraper\IndexCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IndexCatalogTest extends TestCase
{
    #[Test]
    public function it_matches_an_exact_canonical_name(): void
    {
        $this->assertSame('NEPSE Index', IndexCatalog::canonicalize('NEPSE Index'));
        $this->assertSame('Sensitive Index', IndexCatalog::canonicalize('Sensitive Index'));
    }

    #[Test]
    public function it_matches_case_insensitively(): void
    {
        $this->assertSame('NEPSE Index', IndexCatalog::canonicalize('nepse index'));
    }

    /**
     * ShareSansar's live-trading widget truncates long labels with a trailing
     * "." rather than showing them in full — verified against a real capture
     * of the page (see tests/Fixtures/Scraper/live-trading.html).
     */
    #[Test]
    #[DataProvider('truncatedLabels')]
    public function it_recovers_the_untruncated_name(string $truncated, string $expected): void
    {
        $this->assertSame($expected, IndexCatalog::canonicalize($truncated));
    }

    public static function truncatedLabels(): array
    {
        return [
            'development bank' => ['Development Bank Ind.', 'Development Bank Index'],
            'manufacturing' => ['Manufacturing And Pr.', 'Manufacturing And Processing'],
            'sensitive float' => ['Sensitive Float Inde.', 'Sensitive Float Index'],
        ];
    }

    #[Test]
    public function it_refuses_to_guess_an_unrecognised_label(): void
    {
        $this->assertNull(IndexCatalog::canonicalize('Some New Index'));
        $this->assertNull(IndexCatalog::canonicalize(''));
        $this->assertNull(IndexCatalog::canonicalize('   '));
    }

    #[Test]
    public function an_exact_match_wins_before_any_prefix_match_is_attempted(): void
    {
        // "Sensitive Index" and "Sensitive Float Index" share a long common
        // prefix; an untruncated exact match must never fall through to the
        // prefix search and risk landing on the wrong one of the two.
        $this->assertSame('Sensitive Index', IndexCatalog::canonicalize('Sensitive Index'));
        $this->assertSame('Sensitive Float Index', IndexCatalog::canonicalize('Sensitive Float Index'));
    }
}
