<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Enums\AcquisitionChannel;
use App\Enums\TransactionType;

/**
 * Propose how a parcel of shares was acquired, from evidence rather than habit.
 *
 * Pure: every fact it needs is passed in, so the rules can be tested without a
 * database and read without tracing queries.
 *
 * ## The evidence, in order of strength
 *
 *  1. **The transaction type declares it.** `bonus` and `rights` already say
 *     what they were. Nothing beats a record of the actual event.
 *  2. **The broker statement contains the scrip.** A broker statement is a
 *     complete record of broker activity, so a symbol appearing in it was
 *     traded on the floor. This is positive evidence and outranks the par-value
 *     hint below.
 *  3. **The price is exactly par, and the scrip is absent from the broker
 *     statement.** An IPO is allotted at par — Rs 100 for ordinary shares, Rs
 *     10 for mutual-fund units — and paid through MeroShare, so it can never
 *     appear in a broker record. Both halves are required: par alone is a
 *     coincidence a market purchase can produce.
 *  4. **Nothing matched** — `unknown`, reported as such.
 *
 * ## Why absence alone is not enough
 *
 * A scrip missing from the broker statement was not necessarily an IPO. It
 * could have been bought through a different broker, or arrived as a bonus, or
 * simply predate the statement. So absence is only ever half a signal, paired
 * with the par price. In a fictional example, TESTA at 100.0000 may qualify
 * while TESTB at 123.4567 and TESTC at 234.5678 remain `unknown`.
 */
final class AcquisitionChannelDetector
{
    public const BASIS_DECLARED = 'declared_by_type';

    public const BASIS_BROKER = 'broker_statement';

    public const BASIS_PAR = 'par_value';

    public const BASIS_MANUAL = 'manual';

    /**
     * @param  list<float>  $parValues  Prices an issue is allotted at.
     */
    public function __construct(
        private readonly array $parValues = [100.0, 10.0],
        private readonly float $tolerance = 0.005,
    ) {}

    /**
     * @param  array{
     *     type: TransactionType|string,
     *     price: float|null,
     *     symbol?: string|null,
     * }  $transaction
     * @param  list<string>  $brokerTradedSymbols  Symbols the broker statement accounts for.
     * @return array{channel: AcquisitionChannel, basis: string|null, confidence: string}
     */
    public function detect(array $transaction, array $brokerTradedSymbols = []): array
    {
        $type = $transaction['type'] instanceof TransactionType
            ? $transaction['type']
            : TransactionType::tryFrom((string) $transaction['type']);

        if ($type !== null) {
            $declared = AcquisitionChannel::declaredBy($type);

            if ($declared !== null) {
                return $this->result($declared, self::BASIS_DECLARED, 'certain');
            }
        }

        $symbol = strtoupper(trim((string) ($transaction['symbol'] ?? '')));
        $traded = array_map(static fn (string $s): string => strtoupper(trim($s)), $brokerTradedSymbols);

        $price = $transaction['price'] ?? null;

        /*
         * A parcel with no recorded price cannot be evidenced as a market
         * purchase, even when the broker did trade that scrip elsewhere.
         * `secondary` means "bought at a price somebody chose", and an unpriced
         * parcel is the opposite — it is what a statement leaves behind when
         * the shares arrived from outside it. This is not hypothetical: the
         * import seeds seven such parcels (BHDC, BHPL, HRL, SMHL, CLI, and part
         * of KBL and GCIL), every one of them a scrip the statement also trades,
         * so matching on the symbol alone would file them all as trading.
         */
        if ($price === null || $price <= 0.0) {
            return $this->result(AcquisitionChannel::Unknown, null, 'none');
        }

        // Positive evidence beats the par-value hint: a scrip the broker
        // actually settled at a real price was bought on the floor.
        if ($symbol !== '' && in_array($symbol, $traded, true)) {
            return $this->result(AcquisitionChannel::Secondary, self::BASIS_BROKER, 'strong');
        }

        // Par alone proves nothing — a market purchase can land on 100.00. It
        // only becomes evidence when the broker cannot account for the scrip,
        // and only when there is a statement to be absent from.
        if ($traded !== [] && $symbol !== '' && $this->isAtPar($price)) {
            return $this->result(AcquisitionChannel::Ipo, self::BASIS_PAR, 'likely');
        }

        return $this->result(AcquisitionChannel::Unknown, null, 'none');
    }

    public function isAtPar(?float $price): bool
    {
        if ($price === null || $price <= 0.0) {
            return false;
        }

        foreach ($this->parValues as $par) {
            if (abs($price - $par) < $this->tolerance) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a proposal may overwrite what is already stored.
     *
     * A channel a person confirmed is final — re-running detection must never
     * undo a correction, which is the whole reason `channel_confirmed_at`
     * exists. An unconfirmed value is only replaced by a real finding, never by
     * `unknown`: downgrading a previous proposal to nothing would lose ground
     * on every run.
     */
    public function mayOverwrite(
        ?string $storedConfirmedAt,
        AcquisitionChannel $proposed,
    ): bool {
        if ($storedConfirmedAt !== null && $storedConfirmedAt !== '') {
            return false;
        }

        return $proposed !== AcquisitionChannel::Unknown;
    }

    /**
     * @return array{channel: AcquisitionChannel, basis: string|null, confidence: string}
     */
    private function result(AcquisitionChannel $channel, ?string $basis, string $confidence): array
    {
        return ['channel' => $channel, 'basis' => $basis, 'confidence' => $confidence];
    }
}
