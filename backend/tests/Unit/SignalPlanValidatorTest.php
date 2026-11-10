<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\SignalType;
use App\Services\AI\SignalPlanValidator;
use InvalidArgumentException;
use Tests\TestCase;

class SignalPlanValidatorTest extends TestCase
{
    public function test_buy_targets_must_clear_the_entire_entry_band(): void
    {
        $plan = (new SignalPlanValidator)->validate([
            'entry_price' => 100, 'entry_price_max' => 110,
            'stop_loss' => 90, 'target_1' => 105, 'target_2' => 108,
        ], SignalType::Buy, 100, 'TEST');

        $this->assertNull($plan['target_1']);
        $this->assertNull($plan['target_2']);
        $this->assertNull($plan['risk_reward_ratio']);
    }

    public function test_buy_risk_reward_uses_the_conservative_fill_price(): void
    {
        $plan = (new SignalPlanValidator)->validate([
            'entry_price' => 100, 'entry_price_max' => 110,
            'stop_loss' => 90, 'target_1' => 130, 'target_2' => 150,
        ], SignalType::Buy, 100, 'TEST');

        $this->assertSame(1.0, $plan['risk_reward_ratio']);
    }

    public function test_invalid_second_sell_target_is_dropped(): void
    {
        $plan = (new SignalPlanValidator)->validate([
            'entry_price' => 100, 'stop_loss' => 110,
            'target_1' => 90, 'target_2' => 95,
        ], SignalType::Sell, 100, 'TEST');

        $this->assertNull($plan['target_2']);
    }

    public function test_non_finite_levels_are_never_returned(): void
    {
        $plan = (new SignalPlanValidator)->validate([
            'entry_price' => INF, 'stop_loss' => NAN, 'target_1' => '1e999',
        ], SignalType::Buy, 100, 'TEST');

        $this->assertNull($plan['entry_price']);
        $this->assertNull($plan['stop_loss']);
        $this->assertNull($plan['target_1']);
    }

    public function test_invalid_reference_price_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SignalPlanValidator)->validate([], SignalType::Buy, 0, 'TEST');
    }

    public function test_hold_does_not_present_a_directional_risk_reward(): void
    {
        $plan = (new SignalPlanValidator)->validate([
            'entry_price' => 100, 'stop_loss' => 90, 'target_1' => 120,
        ], SignalType::Hold, 100, 'TEST');
        $this->assertNull($plan['risk_reward_ratio']);
    }
}
