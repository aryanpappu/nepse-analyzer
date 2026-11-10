<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\SignalType;
use Illuminate\Support\Facades\Log;

final class SignalPlanValidator
{
    /**
     * Enforce the arithmetic the prompt asks for but a model can still get
     * wrong: a stop above entry on a buy, targets on the wrong side, or levels
     * implying an impossible move. We correct what is safely correctable and
     * drop what is not, rather than storing an incoherent trade plan.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, float|null>
     */
    public function validate(array $data, SignalType $signal, float $reference, string $symbol): array
    {
        if (! is_finite($reference) || $reference <= 0) {
            throw new \InvalidArgumentException('Signal reference price must be positive and finite.');
        }

        $entry = $this->positive($data['entry_price'] ?? null);
        $entryMax = $this->positive($data['entry_price_max'] ?? null);
        $exit = $this->positive($data['exit_price'] ?? null);
        $stop = $this->positive($data['stop_loss'] ?? null);
        $target1 = $this->positive($data['target_1'] ?? null);
        $target2 = $this->positive($data['target_2'] ?? null);

        $issues = [];

        // A level more than 3x away from the current price is not a trade plan,
        // it is a parsing or unit error.
        foreach (['entry_price' => &$entry, 'entry_price_max' => &$entryMax, 'exit_price' => &$exit,
            'stop_loss' => &$stop, 'target_1' => &$target1, 'target_2' => &$target2] as $label => &$value) {
            if ($value !== null && ($value > $reference * 3 || $value < $reference / 3)) {
                $issues[] = "{$label} ({$value}) is implausible against a reference price of {$reference}";
                $value = null;
            }
        }
        unset($value);

        if ($entry !== null && $entryMax !== null && $entryMax < $entry) {
            [$entry, $entryMax] = [$entryMax, $entry];
        }

        if ($signal === SignalType::Buy) {
            // Stop must sit below the entry (or the reference when no entry given).
            $anchor = $entry ?? $reference;

            if ($stop !== null && $stop >= $anchor) {
                $issues[] = "stop_loss ({$stop}) is not below the entry ({$anchor})";
                $stop = null;
            }

            if ($target1 !== null && $target1 <= ($entryMax ?? $anchor)) {
                $issues[] = "target_1 ({$target1}) is not above the entry ({$anchor})";
                $target1 = null;
            }

            if ($target2 !== null && ($target2 <= ($entryMax ?? $anchor) || ($target1 !== null && $target2 <= $target1))) {
                $target2 = null;
            }
        }

        if ($signal === SignalType::Sell) {
            $anchor = $entry ?? $reference;

            // On an exit call the "stop" protects against being wrong about the
            // downside, so it belongs above the current price.
            if ($stop !== null && $stop <= $anchor) {
                $issues[] = "stop_loss ({$stop}) is not above the exit level ({$anchor}) for a sell";
                $stop = null;
            }

            if ($target1 !== null && $target1 >= $anchor) {
                $target1 = null;
            }

            if ($target2 !== null && ($target2 >= $anchor || ($target1 !== null && $target2 >= $target1))) {
                $target2 = null;
            }
        }

        if ($issues !== []) {
            Log::channel('analysis')->warning('Dropped incoherent signal levels', [
                'symbol' => $symbol,
                'signal' => $signal->value,
                'issues' => $issues,
            ]);
        }

        // Risk/reward is derived, never taken from the model — that way it can
        // never disagree with the levels actually stored.
        $riskReward = null;
        // Quote the conservative buy fill at the top of the entry band.
        $anchor = $signal === SignalType::Buy ? ($entryMax ?? $entry ?? $reference) : ($entry ?? $reference);

        if ($signal !== SignalType::Hold && $stop !== null && $target1 !== null) {
            $risk = abs($anchor - $stop);
            $reward = abs($target1 - $anchor);

            if ($risk > 0.01) {
                $riskReward = round($reward / $risk, 2);
            }
        }

        return [
            'entry_price' => $entry,
            'entry_price_max' => $entryMax,
            'exit_price' => $exit,
            'stop_loss' => $stop,
            'target_1' => $target1,
            'target_2' => $target2,
            'risk_reward_ratio' => $riskReward,
        ];
    }

    private function positive(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return is_finite($float) && $float > 0 ? round($float, 2) : null;
    }
}
