<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Portfolio\IpoCapitalService;

/**
 * How a parcel of shares was actually acquired.
 *
 * In Nepal these are not variations on one activity, they are different
 * activities with different skill content and different cash mechanics:
 *
 * - An **IPO** allotment is applied for through MeroShare/CASBA at par (Rs 100),
 *   paid straight from the applicant's own bank, and allotted by lottery. The
 *   only decision involved was to apply. A 4x return on it says nothing about
 *   stock picking.
 * - A **secondary** purchase is a price paid on the floor through a broker.
 *   This is the part that is actually stock picking, and the only part whose
 *   profit is evidence about the investor.
 * - **Bonus** units arrive free and dilute the average cost of the parcel that
 *   earned them. They are a consequence of an earlier acquisition, not one.
 * - **Rights** are subscribed at a declared offer price, usually par, and — like
 *   an IPO — are paid from the bank rather than through the broker.
 * - **Unknown** is a first-class answer, not a placeholder to be tidied away. A
 *   channel that was never recorded and cannot be inferred must print as
 *   unknown; guessing it would silently move money between two buckets whose
 *   whole purpose is to be told apart. Every migration and every detector
 *   defaults here.
 *
 * The cash distinction is the one with teeth. IPO and rights money never
 * touches the broker's ledger, so a portfolio built from a MeroShare import
 * holds shares whose purchase money appears nowhere — see
 * {@see IpoCapitalService} for how that gap is closed,
 * and {@see fundedOutsideBroker()} for which channels have it.
 */
enum AcquisitionChannel: string
{
    case Ipo = 'ipo';
    case Secondary = 'secondary';
    case Bonus = 'bonus';
    case Rights = 'rights';
    case Unknown = 'unknown';

    /**
     * What an unrecorded, uninferrable acquisition is. Never `secondary`:
     * "most of them were bought on market" is an assumption, not a default.
     */
    public static function default(): self
    {
        return self::Unknown;
    }

    public function label(): string
    {
        return match ($this) {
            self::Ipo => 'IPO allotment',
            self::Secondary => 'Secondary market',
            self::Bonus => 'Bonus shares',
            self::Rights => 'Rights issue',
            self::Unknown => 'Channel unknown',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Ipo => 'IPO',
            self::Secondary => 'Market',
            self::Bonus => 'Bonus',
            self::Rights => 'Rights',
            self::Unknown => 'Unknown',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Ipo => 'Applied for through MeroShare/CASBA and allotted at par. Paid from the '
                .'applicant\'s bank, so it never appears in a broker statement.',
            self::Secondary => 'Bought on the floor through a broker at a market price. The only '
                .'channel whose profit is evidence of stock picking.',
            self::Bonus => 'Awarded free against an existing holding. Adds units at no cost and '
                .'dilutes the average cost of the parcel that earned them.',
            self::Rights => 'Subscribed at the declared offer price, usually par. Like an IPO it is '
                .'paid from the bank, not through the broker.',
            self::Unknown => 'Never recorded and not inferrable from the evidence available. Reported '
                .'separately rather than folded into either real channel.',
        };
    }

    /**
     * Whether profit earned here is evidence about the investor's judgement.
     *
     * Only a secondary purchase is: every other channel handed the units over
     * at a price nobody chose.
     */
    public function isMarketPriced(): bool
    {
        return $this === self::Secondary;
    }

    /**
     * Whether the purchase money bypassed the broker's cash ledger.
     *
     * True for IPO and rights: both are paid from the applicant's own bank
     * through CASBA, so a broker statement can never explain them. Bonus is
     * false because nothing was paid; secondary is false because the broker
     * debited it; unknown is false because auto-adding capital on a guess is
     * exactly the invention the honesty rules forbid.
     */
    public function fundedOutsideBroker(): bool
    {
        return $this === self::Ipo || $this === self::Rights;
    }

    /**
     * The channel a transaction type declares about itself, where it declares
     * one at all.
     *
     * `bonus` and `rights` are self-declaring — the ledger already records what
     * they were. `buy` declares nothing: it is the type used for an IPO
     * allotment, a market purchase and a MeroShare import alike, which is
     * precisely why this enum has to exist. `sell` and `dividend` acquire
     * nothing, so they have no channel of their own; a disposal inherits the
     * channel of the lots it consumes.
     */
    public static function declaredBy(TransactionType $type): ?self
    {
        return match ($type) {
            TransactionType::Bonus => self::Bonus,
            TransactionType::Rights => self::Rights,
            TransactionType::Buy, TransactionType::Sell, TransactionType::Dividend => null,
        };
    }

    /**
     * Parse a stored value, falling back to `unknown` rather than throwing.
     *
     * Persisted rows predate this enum and a future value could be written by
     * hand; neither should take a report down, and neither should be silently
     * read as a real channel.
     */
    public static function parse(?string $value): self
    {
        return $value === null ? self::Unknown : (self::tryFrom($value) ?? self::Unknown);
    }

    /**
     * Display order: the two channels the whole feature exists to separate
     * first, then the derived ones, then the gap.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Secondary, self::Ipo, self::Rights, self::Bonus, self::Unknown];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
