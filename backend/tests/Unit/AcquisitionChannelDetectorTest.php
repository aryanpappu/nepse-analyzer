<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\AcquisitionChannel;
use App\Enums\TransactionType;
use App\Services\Portfolio\AcquisitionChannelDetector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AcquisitionChannelDetectorTest extends TestCase
{
    private AcquisitionChannelDetector $detector;

    /** Fictional symbols covered by the synthetic broker statement. */
    private const TRADED = ['TESTA', 'TESTB'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new AcquisitionChannelDetector;
    }

    #[Test]
    public function a_par_priced_scrip_absent_from_the_broker_statement_is_an_ipo(): void
    {
        // A fictional par-priced acquisition absent from the broker statement.
        $result = $this->detector->detect(
            ['type' => TransactionType::Buy, 'price' => 100.0, 'symbol' => 'TESTC'],
            self::TRADED,
        );

        $this->assertSame(AcquisitionChannel::Ipo, $result['channel']);
        $this->assertSame(AcquisitionChannelDetector::BASIS_PAR, $result['basis']);
    }

    #[Test]
    public function a_par_price_is_not_an_ipo_when_the_broker_settled_the_scrip(): void
    {
        // The coincidence case, and the reason absence is required rather than
        // par alone: a market purchase can land on exactly 100.00, and the
        // broker statement is positive proof it was bought on the floor.
        $result = $this->detector->detect(
            ['type' => TransactionType::Buy, 'price' => 100.0, 'symbol' => 'TESTA'],
            self::TRADED,
        );

        $this->assertSame(AcquisitionChannel::Secondary, $result['channel']);
        $this->assertSame(AcquisitionChannelDetector::BASIS_BROKER, $result['basis']);
    }

    #[Test]
    public function mutual_fund_par_of_ten_is_recognised_too(): void
    {
        $result = $this->detector->detect(
            ['type' => TransactionType::Buy, 'price' => 10.0, 'symbol' => 'TESTF'],
            self::TRADED,
        );

        $this->assertSame(AcquisitionChannel::Ipo, $result['channel']);
    }

    #[Test]
    public function an_odd_price_absent_from_the_statement_stays_unknown(): void
    {
        // Fictional odd-cost acquisitions are absent from the statement but
        // are not at par. Their channel must remain unknown.
        foreach ([['TESTC', 123.4567], ['TESTD', 234.5678], ['TESTE', 345.6789]] as [$symbol, $price]) {
            $result = $this->detector->detect(
                ['type' => TransactionType::Buy, 'price' => $price, 'symbol' => $symbol],
                self::TRADED,
            );

            $this->assertSame(AcquisitionChannel::Unknown, $result['channel'], $symbol.' must stay unknown');
            $this->assertNull($result['basis']);
        }
    }

    #[Test]
    public function bonus_and_rights_declare_themselves_whatever_the_price(): void
    {
        $bonus = $this->detector->detect(
            ['type' => TransactionType::Bonus, 'price' => 0.0, 'symbol' => 'TESTA'],
            self::TRADED,
        );
        $this->assertSame(AcquisitionChannel::Bonus, $bonus['channel']);
        $this->assertSame(AcquisitionChannelDetector::BASIS_DECLARED, $bonus['basis']);

        // Even though TESTA *is* broker-traded, the declared type wins.
        $rights = $this->detector->detect(
            ['type' => TransactionType::Rights, 'price' => 100.0, 'symbol' => 'TESTA'],
            self::TRADED,
        );
        $this->assertSame(AcquisitionChannel::Rights, $rights['channel']);
    }

    #[Test]
    public function with_no_broker_statement_nothing_is_inferred_from_par(): void
    {
        // Before the statement is imported there is nothing to be absent from,
        // so par proves nothing at all. Inferring IPO here would flag the whole
        // portfolio on no evidence.
        $result = $this->detector->detect(
            ['type' => TransactionType::Buy, 'price' => 100.0, 'symbol' => 'TESTC'],
            [],
        );

        $this->assertSame(AcquisitionChannel::Unknown, $result['channel']);
    }

    #[Test]
    public function a_confirmed_channel_is_never_overwritten(): void
    {
        // The correction the user made by hand must survive every later run.
        $this->assertFalse(
            $this->detector->mayOverwrite('2001-01-15 12:00:00', AcquisitionChannel::Secondary)
        );
        $this->assertTrue(
            $this->detector->mayOverwrite(null, AcquisitionChannel::Secondary)
        );
    }

    #[Test]
    public function an_unknown_proposal_never_downgrades_a_previous_finding(): void
    {
        // Otherwise a run made before the statement was imported would wipe
        // what a later run had established.
        $this->assertFalse($this->detector->mayOverwrite(null, AcquisitionChannel::Unknown));
    }

    #[Test]
    public function par_detection_tolerates_stored_decimal_noise_but_not_a_near_miss(): void
    {
        $this->assertTrue($this->detector->isAtPar(100.0));
        $this->assertTrue($this->detector->isAtPar(100.0000));
        $this->assertTrue($this->detector->isAtPar(10.0));

        // A fictional near-par average must not be classified as par.
        $this->assertFalse($this->detector->isAtPar(100.37));
        $this->assertFalse($this->detector->isAtPar(10.37));
        $this->assertFalse($this->detector->isAtPar(99.5));
        $this->assertFalse($this->detector->isAtPar(0.0));
        $this->assertFalse($this->detector->isAtPar(null));
    }

    #[Test]
    public function a_missing_symbol_cannot_be_matched_either_way(): void
    {
        $result = $this->detector->detect(
            ['type' => TransactionType::Buy, 'price' => 100.0, 'symbol' => null],
            self::TRADED,
        );

        $this->assertSame(AcquisitionChannel::Unknown, $result['channel']);
    }

    #[Test]
    public function symbol_matching_ignores_case_and_padding(): void
    {
        $result = $this->detector->detect(
            ['type' => TransactionType::Buy, 'price' => 300.50, 'symbol' => ' testb '],
            self::TRADED,
        );

        $this->assertSame(AcquisitionChannel::Secondary, $result['channel']);
    }
}
