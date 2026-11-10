<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Enums\InvestorProfile;
use App\Enums\PositionAction;

/**
 * What to do about one position you already own, for one profile.
 *
 * Distinct from a verdict: a verdict is priced off the market, this is priced
 * off *your* average cost, position size and holding period. The same scrip can
 * be a buy for someone with no position and a trim for you.
 */
final class PositionAdvice
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public readonly string $symbol,
        public readonly string $name,
        public readonly InvestorProfile $profile,
        public readonly PositionAction $action,
        public readonly float $quantity,
        public readonly float $averageCost,
        public readonly ?float $price,
        public readonly ?float $unrealizedGain,
        public readonly ?float $unrealizedGainPercent,
        public readonly ?int $daysHeld,
        public readonly ?float $weightPercent,
        /** Units to buy or sell to act on this advice. */
        public readonly ?float $suggestedQuantity,
        /** Rupees needed (accumulate) or released (trim/exit). */
        public readonly ?float $suggestedAmount,
        public readonly ?float $entryLow,
        public readonly ?float $entryHigh,
        public readonly ?float $stopLoss,
        public readonly ?float $target1,
        public readonly ?float $target2,
        /** Rupees lost if the stop is hit from here. */
        public readonly ?float $riskAtStop,
        public readonly array $reasons,
        public readonly string $headline,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'name' => $this->name,
            'profile' => $this->profile->value,
            'action' => $this->action->value,
            'action_label' => $this->action->label(),
            'urgency' => $this->action->urgency(),
            'is_sell' => $this->action->isSell(),
            'quantity' => $this->quantity,
            'average_cost' => round($this->averageCost, 2),
            'price' => $this->price,
            'unrealized_gain' => $this->unrealizedGain,
            'unrealized_gain_percent' => $this->unrealizedGainPercent,
            'days_held' => $this->daysHeld,
            'weight_percent' => $this->weightPercent,
            'suggested_quantity' => $this->suggestedQuantity,
            'suggested_amount' => $this->suggestedAmount,
            'entry_low' => $this->entryLow,
            'entry_high' => $this->entryHigh,
            'stop_loss' => $this->stopLoss,
            'target_1' => $this->target1,
            'target_2' => $this->target2,
            'risk_at_stop' => $this->riskAtStop,
            'reasons' => $this->reasons,
            'headline' => $this->headline,
        ];
    }
}
