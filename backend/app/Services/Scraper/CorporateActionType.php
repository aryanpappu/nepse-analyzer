<?php

declare(strict_types=1);

namespace App\Services\Scraper;

/**
 * The corporate events this crawler can put on a calendar.
 *
 * A deliberately flat vocabulary rather than a hierarchy: a book close is not
 * an event type here, it is a *date* that hangs off one. Both a dividend and an
 * AGM have their own book close, and the whole point of the calendar is to
 * answer "what closes in the next three days" across every event that has one.
 *
 * Kept as constants in the scraper namespace rather than an App\Enums enum
 * because the strings are a wire format: they are written to
 * `corporate_actions.type` and read back by consumers who must not have to
 * import a PHP enum to filter a query.
 */
final class CorporateActionType
{
    /** A proposed cash/bonus payout for a fiscal year, with its book close. */
    public const DIVIDEND = 'dividend';

    /** Annual general meeting. */
    public const AGM = 'agm';

    /** Special/extraordinary general meeting. */
    public const SGM = 'sgm';

    public const RIGHTS = 'rights';

    public const IPO = 'ipo';

    public const FPO = 'fpo';

    /** IPO tranche reserved for locals of the project-affected district. */
    public const IPO_LOCAL = 'ipo_local';

    /** IPO tranche reserved for Nepali migrant workers. */
    public const IPO_MIGRANT = 'ipo_migrant';

    /** IPO tranche reserved for qualified institutional investors. */
    public const IPO_QII = 'ipo_qii';

    public const MUTUAL_FUND = 'mutual_fund';

    public const DEBENTURE = 'debenture';

    /**
     * ShareSansar's numeric `type` parameter on /existing-issues and
     * /upcoming-issue, mapped to this vocabulary.
     *
     * The numbers are the site's own and are not sequential — 6 is absent from
     * its own tab list — so this is a lookup of what the page actually offers,
     * not a range.
     *
     * @return array<int, string>
     */
    public static function issueFeedTypes(): array
    {
        return [
            1 => self::IPO,
            2 => self::FPO,
            3 => self::RIGHTS,
            4 => self::MUTUAL_FUND,
            5 => self::IPO_LOCAL,
            7 => self::DEBENTURE,
            8 => self::IPO_MIGRANT,
            9 => self::IPO_QII,
        ];
    }

    /**
     * Which meeting a row describes, from the label the feed publishes
     * ("AGM", "6th AGM", "SGM", "Special General Meeting").
     *
     * Defaults to AGM rather than throwing: an unlabelled meeting with a book
     * close is still worth putting on the calendar, and the label is kept
     * verbatim in `title` either way.
     */
    public static function meetingFrom(?string $label): string
    {
        $text = strtolower(trim((string) $label));

        if ($text === '') {
            return self::AGM;
        }

        $isSpecial = str_contains($text, 'sgm')
            || str_contains($text, 'special')
            || str_contains($text, 'extraordinary')
            || str_contains($text, 'egm');

        return $isSpecial ? self::SGM : self::AGM;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::DIVIDEND, self::AGM, self::SGM, self::RIGHTS, self::IPO,
            self::FPO, self::IPO_LOCAL, self::IPO_MIGRANT, self::IPO_QII,
            self::MUTUAL_FUND, self::DEBENTURE,
        ];
    }
}
