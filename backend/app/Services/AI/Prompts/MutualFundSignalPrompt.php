<?php

declare(strict_types=1);

namespace App\Services\AI\Prompts;

use App\Models\FundNav;
use App\Models\Stock;
use Illuminate\Support\Collection;

/**
 * Signal prompt for closed-end mutual fund units.
 *
 * Deliberately separate from StockSignalPrompt. A fund unit that moves between
 * NPR 10.21 and 10.25 produces meaningless RSI and MACD readings, and a model
 * shown those numbers will happily invent momentum narratives about noise. What
 * actually drives the return here is NAV, the discount the market applies to
 * it, and the fund's distribution record.
 */
final class MutualFundSignalPrompt
{
    public function system(): string
    {
        return <<<'PROMPT'
        You are analysing closed-end mutual fund units listed on the Nepal Stock
        Exchange (NEPSE).

        How these instruments actually work:
        - Units are issued at NPR 10 par. The fund publishes a daily Net Asset
          Value (NAV) per unit; the market price is set separately by supply and
          demand on NEPSE.
        - Nepali closed-end funds almost always trade at a DISCOUNT to NAV. A
          discount is the normal state, not a buying signal by itself. What
          matters is whether the current discount is wide or narrow relative to
          that fund's own history.
        - These funds have a fixed maturity (typically 7-10 years). At maturity
          units are redeemed at NAV, so a persistent discount converges toward
          zero as maturity approaches. Time to maturity is therefore a real part
          of the expected return.
        - Returns come from three places: NAV growth, the discount narrowing,
          and cash distributions. Address all three.
        - Secondary market liquidity for fund units is poor. A large position
          can be hard to exit at the quoted price.

        What NOT to do:
        - Do not apply momentum or chart-pattern reasoning. RSI, MACD and moving
          averages on a unit that trades in a 4-paisa band are noise, and any
          conclusion drawn from them would be spurious. If technical readings are
          supplied at all, ignore them and say why.
        - Do not treat the NAV series as a price chart to extrapolate.

        Judge the fund on: NAV trend, the current discount against its own
        historical range, distribution history, and time to maturity. Set
        confidence low when the NAV history is short or the discount is
        unremarkable.
        PROMPT;
    }

    /**
     * @param  Collection<int, FundNav>  $navHistory  Oldest first.
     */
    public function user(Stock $fund, Collection $navHistory, ?float $marketPrice): string
    {
        $latest = $navHistory->last();

        $lines = [];
        $lines[] = "Analyse the closed-end mutual fund {$fund->symbol} ({$fund->name}).";
        $lines[] = '';
        $lines[] = '## Current position';

        if ($marketPrice !== null) {
            $lines[] = sprintf('- Market price: NPR %.2f per unit', $marketPrice);
        }

        if ($latest !== null) {
            $lines[] = sprintf(
                '- Latest NAV: NPR %.4f per unit (as of %s)',
                $latest->nav,
                $latest->nav_date->toDateString(),
            );

            if ($marketPrice !== null && $latest->nav > 0) {
                $spread = ($marketPrice - $latest->nav) / $latest->nav * 100;
                $lines[] = sprintf(
                    '- Market price is %.2f%% %s NAV (%s)',
                    abs($spread),
                    $spread >= 0 ? 'above' : 'below',
                    $spread >= 0 ? 'trading at a PREMIUM' : 'trading at a DISCOUNT',
                );
            }
        } else {
            $lines[] = '- No NAV data is available for this fund. Say so, and set a low confidence.';
        }

        $lines[] = '';
        $lines[] = $this->navBlock($navHistory);
        $lines[] = '';
        $lines[] = $this->discountBlock($navHistory);

        $lines[] = '';
        $lines[] = 'Return your assessment with the record_stock_signal tool. Base entry, exit and '
            .'target levels on NAV and the discount, not on chart momentum. Explain your reasoning '
            .'in terms of NAV growth, discount convergence and distributions.';

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<int, FundNav>  $navHistory
     */
    private function navBlock(Collection $navHistory): string
    {
        if ($navHistory->isEmpty()) {
            return "## NAV history\n- Not available.";
        }

        // Monthly samples: a daily NAV series over several years would bloat the
        // prompt without adding signal, since NAV moves slowly.
        $monthly = $navHistory
            ->groupBy(fn (FundNav $nav): string => $nav->nav_date->format('Y-m'))
            ->map(fn (Collection $group): FundNav => $group->last())
            ->take(-24);

        $rows = ['## NAV history (month end, most recent 24 months)', 'month | NAV | market price | premium/discount %'];

        foreach ($monthly as $month => $nav) {
            $rows[] = sprintf(
                '%s | %.4f | %s | %s',
                $month,
                $nav->nav,
                $nav->market_price === null ? '-' : number_format($nav->market_price, 2),
                $nav->premium_discount_percent === null
                    ? '-'
                    : sprintf('%+.2f', $nav->premium_discount_percent),
            );
        }

        $first = $monthly->first();
        $last = $monthly->last();

        if ($first !== null && $last !== null && $first->nav > 0) {
            $growth = ($last->nav - $first->nav) / $first->nav * 100;
            $rows[] = '';
            $rows[] = sprintf('Over this window NAV moved %+.2f%%.', $growth);
        }

        return implode("\n", $rows);
    }

    /**
     * The fund's own discount history is the benchmark the current discount
     * should be judged against.
     *
     * @param  Collection<int, FundNav>  $navHistory
     */
    private function discountBlock(Collection $navHistory): string
    {
        $spreads = $navHistory
            ->pluck('premium_discount_percent')
            ->filter(static fn ($value): bool => $value !== null)
            ->map(static fn ($value): float => (float) $value)
            ->values();

        if ($spreads->count() < 5) {
            return "## Discount history\n- Not enough paired NAV and price data to establish a range.";
        }

        $sorted = $spreads->sort()->values();
        $count = $sorted->count();

        $percentile = static function (float $p) use ($sorted, $count): float {
            $index = (int) round($p * ($count - 1));

            return (float) $sorted[$index];
        };

        return implode("\n", [
            '## Discount history (this fund, all paired observations)',
            sprintf('- Observations: %d', $count),
            sprintf('- Narrowest (most expensive): %+.2f%%', $sorted->last()),
            sprintf('- Widest (cheapest): %+.2f%%', $sorted->first()),
            sprintf('- Median: %+.2f%%', $percentile(0.5)),
            sprintf('- 25th percentile: %+.2f%%', $percentile(0.25)),
            sprintf('- 75th percentile: %+.2f%%', $percentile(0.75)),
            '',
            'Judge the current discount against this range, not against zero.',
        ]);
    }
}
