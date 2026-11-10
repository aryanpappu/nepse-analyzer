<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Models\CashTransaction;
use App\Models\Portfolio;
use Illuminate\Support\Facades\DB;

/**
 * Whether a cash placement's principal actually moved through the cash ledger.
 *
 * This is the single fact the whole no-double-counting guarantee rests on, so
 * it is deliberately *derived from the ledger* rather than stored as a flag on
 * `cash_placements`: the ledger row is the money moving, and a boolean column
 * could drift out of step with it (amended, voided, or set by hand). Asking
 * "is there a live `placement_debit` row referencing this placement" cannot
 * drift, because the row is the thing that debited the account.
 *
 * A placement recorded *without* settlement is purely informational — nothing
 * left `bank_balance`, so that money is already counted as idle cash and the
 * placement must not be counted again. See
 * {@see PlacementValuator} for how that is enforced.
 *
 * The reference convention (`placement:{id}`) is what links a ledger row back
 * to its placement. `cash_transactions.reference` is a free-form 120-char
 * column already used for broker note numbers, and `type` is a free-form
 * 24-char column, so no migration is needed for these three new types.
 */
final class CashPlacementSettlement
{
    /** Principal leaving a cash account to open a placement. */
    public const TYPE_OPEN = 'placement_debit';

    /** Principal returning to a cash account when a placement is withdrawn. */
    public const TYPE_CLOSE = 'placement_credit';

    /** Interest returning to a cash account (a lump on a reinvested placement). */
    public const TYPE_INTEREST = 'placement_interest';

    private const REFERENCE_PREFIX = 'placement:';

    /** @return list<string> */
    public static function types(): array
    {
        return [self::TYPE_OPEN, self::TYPE_CLOSE, self::TYPE_INTEREST];
    }

    public static function referenceFor(int|string $placementId): string
    {
        return self::REFERENCE_PREFIX.$placementId;
    }

    /**
     * Which of $placementIds were opened with a real cash debit — the only
     * ones whose principal has actually left `cash_in_hand`/`bank_balance`
     * and may therefore be counted as an asset in its own right.
     *
     * Voided rows do not count: voiding the debit puts the money back in the
     * account, so the placement becomes informational again.
     *
     * @param  list<int>  $placementIds
     * @return array<int, true> Keyed by placement id, so lookup is O(1).
     */
    public function settledIds(Portfolio $portfolio, array $placementIds): array
    {
        if ($placementIds === []) {
            return [];
        }

        $references = array_map(
            static fn (int $id): string => self::referenceFor($id),
            $placementIds,
        );

        $found = DB::table('cash_transactions')
            ->where('portfolio_id', $portfolio->id)
            ->where('type', self::TYPE_OPEN)
            ->whereNull('voided_at')
            ->whereIn('reference', $references)
            ->pluck('reference')
            ->all();

        $settled = [];

        foreach ($found as $reference) {
            $settled[(int) substr((string) $reference, strlen(self::REFERENCE_PREFIX))] = true;
        }

        return $settled;
    }

    /**
     * The live ledger rows written for one placement, newest first — what an
     * "undo" or an audit view needs, and what tells a caller whether a
     * withdrawal has already been settled back into cash.
     *
     * @return list<CashTransaction>
     */
    public function rowsFor(Portfolio $portfolio, int|string $placementId): array
    {
        return CashTransaction::query()
            ->where('portfolio_id', $portfolio->id)
            ->whereIn('type', self::types())
            ->whereNull('voided_at')
            ->where('reference', self::referenceFor($placementId))
            ->orderByDesc('transacted_on')
            ->orderByDesc('id')
            ->get()
            ->all();
    }
}
