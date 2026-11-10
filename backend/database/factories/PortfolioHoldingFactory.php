<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Portfolio;
use App\Models\PortfolioHolding;
use App\Models\Stock;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<PortfolioHolding>
 */
class PortfolioHoldingFactory extends Factory
{
    protected $model = PortfolioHolding::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'portfolio_id' => Portfolio::factory(),
            'stock_id' => Stock::factory(),
            'quantity' => 100,
            'average_cost' => 400,
            'realized_gain' => 0,
            'total_fees' => 0,
            'first_purchased_on' => Carbon::today()->subYear()->toDateString(),
            'notes' => null,
        ];
    }

    public function position(float $quantity, float $averageCost): static
    {
        return $this->state(fn (): array => [
            'quantity' => $quantity,
            'average_cost' => $averageCost,
        ]);
    }

    /** A fully sold-out row — kept for history, excluded from open holdings. */
    public function closed(): static
    {
        return $this->state(fn (): array => ['quantity' => 0]);
    }
}
