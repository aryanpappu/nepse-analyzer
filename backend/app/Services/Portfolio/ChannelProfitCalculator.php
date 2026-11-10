<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Enums\AcquisitionChannel;

/**
 * Split profit by how the shares were acquired.
 *
 * Pure — parcels in, buckets out — so the arithmetic is testable without a
 * database and the reconciliation invariant below can be asserted directly.
 *
 * ## Why the split matters more than the total
 *
 * A blended return answers the wrong question. An IPO allotted at par by
 * lottery and a scrip somebody chose to buy are different activities, and only
 * the second is evidence about the investor. Reported together, a handful of
 * IPO allotments can carry a book whose actual stock picking lost money — or,
 * in a fictional example, the reverse: 3,000.00 of subscription funding sits
 * alongside 58,000.00 of broker-traded cost. Trading dominates that example
 * and should be reported separately from allotments.
 *
 * ## The invariant
 *
 * Every bucket's gain sums to the total gain, and every bucket's cost sums to
 * the total cost. A split that does not reconcile is hiding a parcel, so
 * {@see split()} reports `reconciles` rather than leaving the caller to trust it.
 *
 * ## What it refuses to do
 *
 * A parcel with no current price is **excluded and named**, never marked at
 * cost: valuing it at cost would silently report a 0% gain on it and drag the
 * bucket's return toward zero. An empty bucket reports `null` for its return,
 * not `0`.
 */
final class ChannelProfitCalculator
{
    /**
     * @param  list<array{
     *     channel: AcquisitionChannel|string,
     *     symbol?: string|null,
     *     quantity: float,
     *     cost_basis: float,
     *     market_value: float|null,
     * }>  $parcels
     * @return array<string, mixed>
     */
    public function split(array $parcels): array
    {
        $buckets = [];
        $excluded = [];

        foreach (AcquisitionChannel::ordered() as $channel) {
            $buckets[$channel->value] = [
                'channel' => $channel->value,
                'label' => $channel->label(),
                'short_label' => $channel->shortLabel(),
                'is_market_priced' => $channel->isMarketPriced(),
                'funded_outside_broker' => $channel->fundedOutsideBroker(),
                'parcels' => 0,
                'quantity' => 0.0,
                'cost_basis' => 0.0,
                'market_value' => 0.0,
                'gain' => 0.0,
                'return_percent' => null,
                'share_of_cost_percent' => null,
                'excluded_symbols' => [],
            ];
        }

        foreach ($parcels as $parcel) {
            $channel = $parcel['channel'] instanceof AcquisitionChannel
                ? $parcel['channel']
                : AcquisitionChannel::parse((string) $parcel['channel']);

            $key = $channel->value;
            $symbol = (string) ($parcel['symbol'] ?? '');

            // No price is not a zero price. Counting it at cost would report a
            // silent 0% on the parcel and pull the bucket's return toward it.
            if ($parcel['market_value'] === null) {
                $buckets[$key]['excluded_symbols'][] = $symbol;
                $excluded[] = ['symbol' => $symbol, 'channel' => $key, 'reason' => 'No price available.'];

                continue;
            }

            $buckets[$key]['parcels']++;
            $buckets[$key]['quantity'] = round($buckets[$key]['quantity'] + $parcel['quantity'], 2);
            $buckets[$key]['cost_basis'] = round($buckets[$key]['cost_basis'] + $parcel['cost_basis'], 2);
            $buckets[$key]['market_value'] = round($buckets[$key]['market_value'] + $parcel['market_value'], 2);
        }

        $totalCost = 0.0;
        $totalValue = 0.0;
        $measured = 0;

        foreach ($buckets as $bucket) {
            $totalCost = round($totalCost + $bucket['cost_basis'], 2);
            $totalValue = round($totalValue + $bucket['market_value'], 2);
            $measured += $bucket['parcels'];
        }

        foreach ($buckets as $key => $bucket) {
            $gain = round($bucket['market_value'] - $bucket['cost_basis'], 2);

            $buckets[$key]['gain'] = $gain;
            $buckets[$key]['return_percent'] = $bucket['cost_basis'] > 0.0
                ? round($gain / $bucket['cost_basis'] * 100, 2)
                : null;
            $buckets[$key]['share_of_cost_percent'] = $totalCost > 0.0
                ? round($bucket['cost_basis'] / $totalCost * 100, 2)
                : null;
            $buckets[$key]['excluded_symbols'] = array_values(array_filter($bucket['excluded_symbols']));
        }

        $totalGain = round($totalValue - $totalCost, 2);
        $bucketGain = round(array_sum(array_column($buckets, 'gain')), 2);

        return [
            'channels' => array_values(array_filter(
                $buckets,
                static fn (array $b): bool => $b['parcels'] > 0 || $b['excluded_symbols'] !== [],
            )),
            'total' => [
                'parcels' => $measured,
                'cost_basis' => $measured > 0 ? $totalCost : null,
                'market_value' => $measured > 0 ? $totalValue : null,
                'gain' => $measured > 0 ? $totalGain : null,
                'return_percent' => $totalCost > 0.0 ? round($totalGain / $totalCost * 100, 2) : null,
            ],
            // Only the market-priced bucket is evidence about stock picking;
            // surfaced separately so the honest figure is not buried.
            'market_priced' => $this->marketPriced($buckets),
            'excluded' => $excluded,
            'coverage' => [
                'parcels_measured' => $measured,
                'parcels_excluded' => count($excluded),
                'complete' => $excluded === [],
                'note' => $excluded === [] ? null : sprintf(
                    '%d parcel(s) had no price and were excluded rather than valued at cost: %s.',
                    count($excluded),
                    implode(', ', array_values(array_unique(array_column($excluded, 'symbol')))),
                ),
            ],
            'reconciles' => abs($bucketGain - $totalGain) < 0.01,
            'reconciliation_error' => round($bucketGain - $totalGain, 2),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $buckets
     * @return array<string, mixed>
     */
    private function marketPriced(array $buckets): array
    {
        $bucket = $buckets[AcquisitionChannel::Secondary->value];

        return [
            'cost_basis' => $bucket['parcels'] > 0 ? $bucket['cost_basis'] : null,
            'market_value' => $bucket['parcels'] > 0 ? $bucket['market_value'] : null,
            'gain' => $bucket['parcels'] > 0 ? $bucket['gain'] : null,
            'return_percent' => $bucket['return_percent'],
            'note' => 'Only shares bought on the floor at a chosen price. The other channels '
                .'handed the units over at a price nobody picked, so their profit is not evidence '
                .'about stock selection.',
        ];
    }
}
