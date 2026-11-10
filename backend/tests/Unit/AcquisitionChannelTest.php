<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\AcquisitionChannel;
use App\Enums\TransactionType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rules that decide whether a channel carries its own capital, and whether
 * its profit says anything about the investor. Both are consequential enough to
 * pin down: getting `fundedOutsideBroker()` wrong either invents capital that
 * was never paid or leaves real capital uncounted.
 */
final class AcquisitionChannelTest extends TestCase
{
    #[Test]
    public function an_unrecorded_channel_defaults_to_unknown_not_to_market(): void
    {
        // "Most were probably bought on market" is an assumption. Reporting it
        // as `secondary` would move an IPO's profit into the bucket that is
        // supposed to be evidence of stock picking.
        $this->assertSame(AcquisitionChannel::Unknown, AcquisitionChannel::default());
        $this->assertSame(AcquisitionChannel::Unknown, AcquisitionChannel::parse(null));
        $this->assertSame(AcquisitionChannel::Unknown, AcquisitionChannel::parse(''));
    }

    #[Test]
    public function an_unrecognised_stored_value_reads_as_unknown_rather_than_throwing(): void
    {
        // A hand-edited or future value must not take a report down, and must
        // not be read as a real channel either.
        $this->assertSame(AcquisitionChannel::Unknown, AcquisitionChannel::parse('margin_purchase'));
    }

    #[Test]
    public function only_ipo_and_rights_are_funded_outside_the_broker(): void
    {
        // These two are paid from the applicant's bank via CASBA, so no broker
        // statement can ever explain them — they are the reason the capital
        // service exists.
        $this->assertTrue(AcquisitionChannel::Ipo->fundedOutsideBroker());
        $this->assertTrue(AcquisitionChannel::Rights->fundedOutsideBroker());

        // The broker debited this one.
        $this->assertFalse(AcquisitionChannel::Secondary->fundedOutsideBroker());
        // Nothing was paid at all.
        $this->assertFalse(AcquisitionChannel::Bonus->fundedOutsideBroker());
        // Adding capital on a guess is the invention the honesty rules forbid.
        $this->assertFalse(AcquisitionChannel::Unknown->fundedOutsideBroker());
    }

    #[Test]
    public function only_a_secondary_purchase_is_evidence_of_stock_picking(): void
    {
        $this->assertTrue(AcquisitionChannel::Secondary->isMarketPriced());

        foreach ([
            AcquisitionChannel::Ipo,
            AcquisitionChannel::Rights,
            AcquisitionChannel::Bonus,
            AcquisitionChannel::Unknown,
        ] as $channel) {
            $this->assertFalse($channel->isMarketPriced(), $channel->value.' is not market priced');
        }
    }

    #[Test]
    public function bonus_and_rights_declare_themselves_but_buy_does_not(): void
    {
        $this->assertSame(AcquisitionChannel::Bonus, AcquisitionChannel::declaredBy(TransactionType::Bonus));
        $this->assertSame(AcquisitionChannel::Rights, AcquisitionChannel::declaredBy(TransactionType::Rights));

        // `buy` is used for an IPO allotment, a market purchase and a MeroShare
        // snapshot row alike. That ambiguity is the whole reason this enum
        // exists, so it must resolve to nothing rather than to a default.
        $this->assertNull(AcquisitionChannel::declaredBy(TransactionType::Buy));

        // A disposal acquires nothing; it inherits the channel of the lots it
        // consumes.
        $this->assertNull(AcquisitionChannel::declaredBy(TransactionType::Sell));
        $this->assertNull(AcquisitionChannel::declaredBy(TransactionType::Dividend));
    }

    #[Test]
    public function every_case_has_a_label_and_a_description(): void
    {
        foreach (AcquisitionChannel::cases() as $channel) {
            $this->assertNotSame('', $channel->label());
            $this->assertNotSame('', $channel->shortLabel());
            $this->assertNotSame('', $channel->description());
        }
    }

    #[Test]
    public function the_display_order_covers_every_case_exactly_once(): void
    {
        $ordered = AcquisitionChannel::ordered();

        $this->assertCount(count(AcquisitionChannel::cases()), $ordered);
        $this->assertSame($ordered, array_values(array_unique($ordered, SORT_REGULAR)));

        // The two the feature exists to separate lead.
        $this->assertSame(AcquisitionChannel::Secondary, $ordered[0]);
        $this->assertSame(AcquisitionChannel::Ipo, $ordered[1]);
        // The gap is reported last, not hidden.
        $this->assertSame(AcquisitionChannel::Unknown, $ordered[array_key_last($ordered)]);
    }

    #[Test]
    public function values_round_trip_through_parse(): void
    {
        foreach (AcquisitionChannel::values() as $value) {
            $this->assertSame($value, AcquisitionChannel::parse($value)->value);
        }
    }
}
