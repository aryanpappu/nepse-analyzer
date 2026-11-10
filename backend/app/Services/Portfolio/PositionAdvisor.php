<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Enums\InvestorProfile;
use App\Enums\PositionAction;
use App\Enums\VerdictGrade;
use App\Models\Portfolio;
use App\Models\PortfolioHolding;
use App\Models\ProfileVerdict;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns "I own this" into "do this".
 *
 * The rules are ordered by how much they cost you if ignored: a breached stop
 * outranks a reached target, which outranks a size top-up. Whatever fires
 * first wins, so a position can never be told to accumulate and exit at once.
 *
 * Everything is derived from the ledger's average cost, so the advice changes
 * when you trade, not only when the market moves.
 */
final class PositionAdvisor
{
    public function __construct(private readonly PortfolioValuationService $valuation) {}

    /**
     * @return list<PositionAdvice> Most urgent first.
     */
    public function adviseAll(Portfolio $portfolio, InvestorProfile $profile): array
    {
        $holdings = $portfolio->openHoldings()->with(['stock'])->get();

        if ($holdings->isEmpty()) {
            return [];
        }

        $verdicts = $this->latestVerdicts($holdings, $profile);
        $totalValue = $this->pricedValue($holdings);
        $investableCash = $portfolio->investableCash();

        $advice = $holdings
            ->map(fn (PortfolioHolding $holding): ?PositionAdvice => $this->advise(
                $holding,
                $profile,
                $verdicts->get($holding->stock_id),
                $totalValue,
                $investableCash,
            ))
            ->filter()
            ->values()
            ->all();

        usort($advice, static function (PositionAdvice $a, PositionAdvice $b): int {
            return [$b->action->urgency(), abs($b->unrealizedGain ?? 0.0)]
                <=> [$a->action->urgency(), abs($a->unrealizedGain ?? 0.0)];
        });

        return $advice;
    }

    public function advise(
        PortfolioHolding $holding,
        InvestorProfile $profile,
        ?ProfileVerdict $verdict,
        float $portfolioValue,
        float $investableCash,
    ): ?PositionAdvice {
        $stock = $holding->stock;

        if ($stock === null || (float) $holding->quantity <= 0.0) {
            return null;
        }

        $quantity = (float) $holding->quantity;
        $average = (float) $holding->average_cost;
        $price = $holding->currentPrice();
        $value = $holding->currentValue();
        $reasons = [];

        $daysHeld = $holding->first_purchased_on === null
            ? null
            : (int) $holding->first_purchased_on->diffInDays(Carbon::today());

        $weight = $portfolioValue > 0.0 && $value !== null
            ? round($value / $portfolioValue * 100, 2)
            : null;

        $action = $this->decide($holding, $profile, $verdict, $price, $average, $daysHeld, $weight, $reasons);

        [$suggestedQuantity, $suggestedAmount] = $this->size(
            $action, $holding, $profile, $price, $portfolioValue, $investableCash, $weight,
        );

        // Report the stop that governs this position, so the number on screen is
        // the one the advice was actually reasoned from.
        $governing = $this->effectiveStop($profile, $verdict, $average);
        $stop = $governing['level'] ?? $verdict?->stop_loss;
        $riskAtStop = $stop !== null && $price !== null && $price > $stop
            ? round(($price - $stop) * $quantity, 2)
            : null;

        return new PositionAdvice(
            symbol: $stock->symbol,
            name: $stock->name,
            profile: $profile,
            action: $action,
            quantity: $quantity,
            averageCost: $average,
            price: $price,
            unrealizedGain: $holding->unrealizedGain(),
            unrealizedGainPercent: $holding->unrealizedGainPercent(),
            daysHeld: $daysHeld,
            weightPercent: $weight,
            suggestedQuantity: $suggestedQuantity,
            suggestedAmount: $suggestedAmount,
            entryLow: $verdict?->entry_low,
            entryHigh: $verdict?->entry_high,
            stopLoss: $stop,
            target1: $verdict?->target_1,
            target2: $verdict?->target_2,
            riskAtStop: $riskAtStop,
            reasons: $reasons,
            headline: $this->headline($action, $stock->symbol, $price, $verdict, $suggestedQuantity, $stop),
        );
    }

    /**
     * The decision ladder. First rule that fires wins.
     *
     * @param  list<string>  $reasons
     */
    private function decide(
        PortfolioHolding $holding,
        InvestorProfile $profile,
        ?ProfileVerdict $verdict,
        ?float $price,
        float $average,
        ?int $daysHeld,
        ?float $weight,
        array &$reasons,
    ): PositionAction {
        if ($price === null) {
            $reasons[] = 'No recent price for this scrip, so no action can be judged.';

            return PositionAction::Hold;
        }

        $gainPercent = $average > 0.0 ? ($price - $average) / $average * 100 : null;

        // 1. Stop breached — measured against the right reference.
        //
        //    A verdict's stop is priced for someone entering today, so applying
        //    it directly to a position bought months ago at a different price
        //    fires constantly and means nothing. What governs an open position is
        //    its own cost: lose more than the profile tolerates and it is cut.
        //
        //    The exception is a position already in profit far enough that the
        //    technical stop sits above cost — then respecting it is a trailing
        //    stop that locks the gain in, which is the stricter and better rule.
        $stop = $this->effectiveStop($profile, $verdict, $average);

        if ($stop !== null && $price <= $stop['level']) {
            $reasons[] = $stop['trailing']
                ? sprintf('Price Rs %s has fallen back through the Rs %s trailing stop, '
                    .'giving up a gain on a Rs %s cost.',
                    number_format($price, 2), number_format($stop['level'], 2), number_format($average, 2))
                : sprintf('Price Rs %s is more than %.0f%% below your Rs %s average cost, '
                    .'which is past what a %s should carry.',
                    number_format($price, 2), $profile->maxStopLoss() * 100,
                    number_format($average, 2), strtolower($profile->label()));

            return PositionAction::StopLoss;
        }

        // 2. Thesis gone. Only acted on when the evidence is clearly against the
        //    position — an Avoid on a scrip you are already up 40% on is a trim,
        //    handled below, not a panic exit.
        if ($verdict?->grade === VerdictGrade::Avoid) {
            $reasons[] = sprintf('The %s playbook now rates this Avoid (score %.0f/100).',
                $profile->shortLabel(), $verdict->score);

            if ($gainPercent === null || $gainPercent < 10.0) {
                return PositionAction::Exit;
            }
        }

        // 3. Second target reached. Momentum closes; the slower profiles trim
        //    and let the rest run.
        if ($verdict?->target_2 !== null && $price >= $verdict->target_2) {
            $reasons[] = sprintf('Second target Rs %s reached.', number_format($verdict->target_2, 2));

            return $profile === InvestorProfile::Momentum
                ? PositionAction::Exit
                : PositionAction::Trim;
        }

        // 4. First target reached — take something off the table.
        if ($verdict?->target_1 !== null && $price >= $verdict->target_1) {
            $reasons[] = sprintf('First target Rs %s reached (up %s%% on cost).',
                number_format($verdict->target_1, 2),
                $gainPercent === null ? '—' : number_format($gainPercent, 1));

            return PositionAction::Trim;
        }

        // 5. The hold window has expired *and* the setup no longer supports the
        //    position. Both halves matter: a long-held position viewed through a
        //    swing lens is always past the window, so the window alone would tell
        //    every investor to liquidate the moment they switched profile. What
        //    makes it dead money is time plus a thesis that has stopped working.
        [, $maxSessions] = $profile->holdSessions();
        $maxDays = (int) round($maxSessions * 7 / 5);
        $windowExpired = $daysHeld !== null
            && $daysHeld > $maxDays
            && $profile !== InvestorProfile::LongTerm;

        if ($windowExpired && ($verdict === null || ! $verdict->grade->isActionable())) {
            $reasons[] = sprintf('Held %d days, past the %s window of %s, and the setup no longer rates a buy.',
                $daysHeld, $profile->shortLabel(), $profile->holdLabel());

            return PositionAction::Exit;
        }

        // 6. Position has outgrown its cap — trim on size alone, regardless of
        //    how well it has done. This is the rule that stops one winner
        //    becoming the whole portfolio.
        $cap = $profile->maxPositionWeight() * 100;

        if ($weight !== null && $weight > $cap * 1.5) {
            $reasons[] = sprintf('%.1f%% of the portfolio, against a %.0f%% cap for this profile.',
                $weight, $cap);

            return PositionAction::Trim;
        }

        // 7. Room to add and the playbook still likes it.
        if ($verdict !== null && $verdict->grade->isActionable() && $weight !== null && $weight < $cap) {
            if ($verdict->price_in_zone) {
                $reasons[] = sprintf('Still a %s at %.0f/100, and price is inside the Rs %s–%s buy zone.',
                    $verdict->grade->label(), $verdict->score,
                    number_format((float) $verdict->entry_low, 2),
                    number_format((float) $verdict->entry_high, 2));
                $reasons[] = sprintf('Position is %.1f%% of the portfolio, under the %.0f%% cap.', $weight, $cap);

                return PositionAction::Accumulate;
            }

            $reasons[] = sprintf('Still a %s, but price Rs %s is outside the Rs %s–%s zone.',
                $verdict->grade->label(), number_format($price, 2),
                number_format((float) $verdict->entry_low, 2),
                number_format((float) $verdict->entry_high, 2));

            return PositionAction::AddOnDip;
        }

        if ($windowExpired) {
            $reasons[] = sprintf('Held %d days, longer than this profile normally would (%s), '
                .'but the setup still rates a buy.', $daysHeld, $profile->holdLabel());
        }

        if ($verdict === null) {
            $reasons[] = sprintf('No %s verdict yet — run nepse:playbook after the next scrape.',
                $profile->shortLabel());
        } else {
            $reasons[] = sprintf('Thesis intact (%s, %.0f/100) and nothing to do at Rs %s.',
                $verdict->grade->label(), $verdict->score, number_format($price, 2));
        }

        return PositionAction::Hold;
    }

    /**
     * The stop that actually governs an open position.
     *
     * Below cost, the profile's loss tolerance from the average cost governs.
     * Once the technical stop has climbed above cost, that becomes a trailing
     * stop and is used instead, because it protects a realised gain rather than
     * merely limiting a loss.
     *
     * @return array{level: float, trailing: bool}|null
     */
    private function effectiveStop(InvestorProfile $profile, ?ProfileVerdict $verdict, float $average): ?array
    {
        if ($average <= 0.0) {
            return null;
        }

        $costStop = $average * (1 - $profile->maxStopLoss());
        $technical = $verdict?->stop_loss;

        if ($technical !== null && $technical > $average) {
            return ['level' => round($technical, 2), 'trailing' => true];
        }

        return ['level' => round($costStop, 2), 'trailing' => false];
    }

    /**
     * How many units the action implies.
     *
     * Accumulate is capped by both the profile's weight limit and the cash you
     * actually have — advice you cannot fund is not advice. Trims are staged
     * (a third at the first target, half at the second) so a position is never
     * closed by a single rule firing.
     *
     * @return array{?float, ?float}
     */
    private function size(
        PositionAction $action,
        PortfolioHolding $holding,
        InvestorProfile $profile,
        ?float $price,
        float $portfolioValue,
        float $investableCash,
        ?float $weight,
    ): array {
        if ($price === null || $price <= 0.0) {
            return [null, null];
        }

        $quantity = (float) $holding->quantity;

        return match ($action) {
            PositionAction::Accumulate => (function () use ($profile, $portfolioValue, $investableCash, $price, $weight): array {
                $capValue = $profile->maxPositionWeight() * $portfolioValue;
                $currentValue = ($weight ?? 0.0) / 100 * $portfolioValue;
                $room = max(0.0, $capValue - $currentValue);
                $budget = min($room, $investableCash);
                $units = floor($budget / $price);

                return $units < 1 ? [null, null] : [$units, round($units * $price, 2)];
            })(),

            PositionAction::Trim => (function () use ($quantity, $price): array {
                $units = floor($quantity / 3);

                return $units < 1 ? [null, null] : [$units, round($units * $price, 2)];
            })(),

            PositionAction::Exit, PositionAction::StopLoss => [$quantity, round($quantity * $price, 2)],

            default => [null, null],
        };
    }

    /**
     * The one-line instruction.
     *
     * $governingStop is passed in rather than read off the verdict so the number
     * quoted here is the same one the decision was made from — quoting the
     * entry-side stop next to a cost-based reason reads as a contradiction.
     */
    private function headline(
        PositionAction $action,
        string $symbol,
        ?float $price,
        ?ProfileVerdict $verdict,
        ?float $suggestedQuantity,
        ?float $governingStop,
    ): string {
        $units = $suggestedQuantity === null
            ? null
            : rtrim(rtrim(number_format($suggestedQuantity, 2, '.', ','), '0'), '.');

        return match ($action) {
            PositionAction::StopLoss => $governingStop === null
                ? sprintf('Sell %s — its stop has been breached.', $symbol)
                : sprintf('Sell %s — stop at Rs %s has been breached.',
                    $symbol, number_format($governingStop, 2)),
            PositionAction::Exit => sprintf('Close %s%s.', $symbol,
                $units === null ? '' : sprintf(' — all %s units', $units)),
            PositionAction::Trim => $units === null
                ? sprintf('Take some %s off the table.', $symbol)
                : sprintf('Sell %s units of %s and let the rest run.', $units, $symbol),
            PositionAction::Accumulate => $units === null
                ? sprintf('Add to %s — no cash headroom for a full lot right now.', $symbol)
                : sprintf('Buy %s more units of %s at up to Rs %s.', $units, $symbol,
                    number_format((float) ($verdict?->entry_high ?? $price), 2)),
            PositionAction::AddOnDip => sprintf('Wait for %s to trade back to Rs %s before adding.',
                $symbol, number_format((float) ($verdict?->entry_high ?? $price), 2)),
            PositionAction::Hold => sprintf('Hold %s.', $symbol),
        };
    }

    /**
     * @param  Collection<int, PortfolioHolding>  $holdings
     * @return Collection<int, ProfileVerdict> Keyed by stock id.
     */
    private function latestVerdicts(Collection $holdings, InvestorProfile $profile): Collection
    {
        return ProfileVerdict::query()
            ->whereIn('stock_id', $holdings->pluck('stock_id')->all())
            ->forProfile($profile)
            ->latestPerProfile()
            ->get()
            ->keyBy('stock_id');
    }

    /**
     * @param  Collection<int, PortfolioHolding>  $holdings
     */
    private function pricedValue(Collection $holdings): float
    {
        return round((float) $holdings->sum(static fn (PortfolioHolding $h): float => $h->currentValue() ?? 0.0), 2);
    }
}
