<?php

declare(strict_types=1);

namespace App\Services\Playbook;

use App\Enums\InvestorProfile;
use App\Enums\VerdictGrade;

/**
 * One profile's read on one scrip at one close, plus the trade geometry.
 *
 * The zone fields are what the chart draws: a band you are willing to buy in,
 * a line where you admit you were wrong, and two lines where you take money
 * off. Having the engine emit them means the plan exists before the trade,
 * which is the only time it is worth anything.
 */
final class PlaybookVerdict
{
    /**
     * @param  array<string, float>  $scores  Bucket name => 0-100 sub-score.
     * @param  list<array{bucket: string, text: string, effect: string}>  $reasons
     */
    public function __construct(
        public readonly InvestorProfile $profile,
        public readonly VerdictGrade $grade,
        public readonly float $score,
        public readonly float $referencePrice,
        public readonly ?float $entryLow,
        public readonly ?float $entryHigh,
        public readonly ?float $stopLoss,
        public readonly ?float $target1,
        public readonly ?float $target2,
        public readonly ?float $riskReward,
        public readonly array $scores,
        public readonly array $reasons,
        public readonly string $summary,
    ) {}

    /**
     * Is today's price inside the buy zone?
     *
     * Drives the "buy now" vs "wait for the dip" split in the UI, and the
     * Accumulate vs AddOnDip split in the position advisor.
     */
    public function priceInZone(): bool
    {
        if ($this->entryLow === null || $this->entryHigh === null) {
            return false;
        }

        return $this->referencePrice >= $this->entryLow
            && $this->referencePrice <= $this->entryHigh;
    }

    public function entryMid(): ?float
    {
        if ($this->entryLow === null || $this->entryHigh === null) {
            return null;
        }

        return round(($this->entryLow + $this->entryHigh) / 2, 2);
    }

    /**
     * Upside to the first target from the entry midpoint, as a percentage.
     */
    public function expectedReturnPercent(): ?float
    {
        $mid = $this->entryMid();

        if ($mid === null || $mid <= 0.0 || $this->target1 === null) {
            return null;
        }

        return round(($this->target1 - $mid) / $mid * 100, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'profile' => $this->profile->value,
            'profile_label' => $this->profile->shortLabel(),
            'grade' => $this->grade->value,
            'grade_label' => $this->grade->label(),
            'score' => round($this->score, 1),
            'reference_price' => round($this->referencePrice, 2),
            'entry_low' => $this->entryLow,
            'entry_high' => $this->entryHigh,
            'stop_loss' => $this->stopLoss,
            'target_1' => $this->target1,
            'target_2' => $this->target2,
            'risk_reward' => $this->riskReward,
            'price_in_zone' => $this->priceInZone(),
            'expected_return_percent' => $this->expectedReturnPercent(),
            'hold_label' => $this->profile->holdLabel(),
            'scores' => array_map(static fn (float $v): float => round($v, 1), $this->scores),
            'reasons' => $this->reasons,
            'summary' => $this->summary,
        ];
    }
}
