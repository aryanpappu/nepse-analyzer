<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scraper\InstrumentClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InstrumentClassifierTest extends TestCase
{
    private InstrumentClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new InstrumentClassifier;
    }

    #[DataProvider('fundNames')]
    public function test_fund_names_are_recognised(string $name): void
    {
        $this->assertSame(
            InstrumentClassifier::MUTUAL_FUND,
            $this->classifier->fromName($name),
            "\"{$name}\" should classify as a mutual fund",
        );
    }

    public static function fundNames(): array
    {
        return [
            ['NIBL Sahabhagita Fund'],
            ['Nabil Flexi Cap Fund'],
            ['Citizens Super 30 Mutual Fund'],
            ['Laxmi Value Fund-II'],
            ['Global IME Samunnat Scheme 1'],
            // Nepali naming: "Yojana" = scheme, "Kosh" = fund. Matching only the
            // English words left a large part of the fund universe unclassified.
            ['Kumari Sabal Yojana'],
            ['Garima Samriddhi Yojana'],
            ['Nepal Life Samriddhi Lagani Yojana'],
            ['Laxmi Unnati Kosh'],
        ];
    }

    #[DataProvider('companyNamesThatMentionFunds')]
    public function test_fund_management_companies_are_not_funds(string $name): void
    {
        // These run funds; they are ordinary listed companies.
        $this->assertSame(
            InstrumentClassifier::EQUITY,
            $this->classifier->fromName($name),
            "\"{$name}\" is a company, not a fund",
        );
    }

    public static function companyNamesThatMentionFunds(): array
    {
        return [
            ['Aadhyanta Fund Management Limited'],
            ['Himalayan Infrastructure Fund Limited'],
            ['Global Equity Fund Limited'],
        ];
    }

    public function test_debentures_and_promoters_are_recognised_by_name(): void
    {
        $this->assertSame(
            InstrumentClassifier::DEBENTURE,
            $this->classifier->fromName('10.25% Civil Bank Debenture 2088'),
        );
        $this->assertSame(
            InstrumentClassifier::PROMOTER,
            $this->classifier->fromName('Nepal Investment Bank Limited Promoter Share'),
        );
    }

    public function test_a_trailing_parenthetical_does_not_defeat_the_corporate_suffix(): void
    {
        // "Bottlers Nepal Limited (Balaju)" ends in "(Balaju)", so a naive
        // "ends with Limited" test misses it and the scrip lands in review.
        $this->assertSame(
            InstrumentClassifier::EQUITY,
            $this->classifier->fromName('Bottlers Nepal Limited (Balaju)'),
        );
        $this->assertSame(
            InstrumentClassifier::EQUITY,
            $this->classifier->fromName('Himalayan Laghubitta Bittiya Sanstha Limited (Former Civil Laghubitta)'),
        );
    }

    public function test_name_wins_over_a_fund_shaped_ticker(): void
    {
        // NMBMF and SWMF have MF tickers but are microfinance companies.
        $this->assertSame(
            InstrumentClassifier::MUTUAL_FUND,
            $this->classifier->fromSymbol('NMBMF'),
            'the ticker alone looks like a fund',
        );
        $this->assertSame(
            InstrumentClassifier::EQUITY,
            $this->classifier->fromName('NMB Laghubitta Bittiya Sanstha Limited'),
            'but the name settles it',
        );
    }

    #[DataProvider('debentureTickers')]
    public function test_debenture_ticker_shapes(string $symbol): void
    {
        $this->assertSame(InstrumentClassifier::DEBENTURE, $this->classifier->fromSymbol($symbol));
    }

    public static function debentureTickers(): array
    {
        // Real NEPSE debentures: issuer code, "D", then the BS maturity year.
        return [['GWFD83'], ['CBLD88'], ['ICFCD89'], ['RBBD2088'], ['1025BOKL86']];
    }

    #[DataProvider('fundTickers')]
    public function test_fund_ticker_shapes(string $symbol): void
    {
        $this->assertSame(InstrumentClassifier::MUTUAL_FUND, $this->classifier->fromSymbol($symbol));
    }

    public static function fundTickers(): array
    {
        return [['NIBLSF'], ['NICADF'], ['NFCF'], ['GIBF1'], ['LVF2'], ['C30MF'], ['RBBF40']];
    }

    public function test_an_unrecognised_ticker_returns_null_rather_than_guessing_equity(): void
    {
        // Returning null is what routes the scrip to manual review instead of
        // being silently analysed with the wrong model.
        $this->assertNull($this->classifier->fromSymbol('CIT'));
        $this->assertNull($this->classifier->fromSymbol('NMB50'));
    }

    public function test_promoter_ticker_shape(): void
    {
        $this->assertSame(InstrumentClassifier::PROMOTER, $this->classifier->fromSymbol('NIBPO'));
    }

    public function test_only_equities_and_funds_are_analysable(): void
    {
        $this->assertTrue($this->classifier->isAnalysable(InstrumentClassifier::EQUITY));
        $this->assertTrue($this->classifier->isAnalysable(InstrumentClassifier::MUTUAL_FUND));

        // Fixed-coupon instruments and undecided scrips must not be analysed.
        $this->assertFalse($this->classifier->isAnalysable(InstrumentClassifier::DEBENTURE));
        $this->assertFalse($this->classifier->isAnalysable(InstrumentClassifier::PREFERENCE));
        $this->assertFalse($this->classifier->isAnalysable(InstrumentClassifier::UNKNOWN));
    }

    public function test_only_funds_use_the_nav_pipeline(): void
    {
        $this->assertTrue($this->classifier->usesNav(InstrumentClassifier::MUTUAL_FUND));
        $this->assertFalse($this->classifier->usesNav(InstrumentClassifier::EQUITY));
    }
}
