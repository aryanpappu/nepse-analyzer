<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use App\Models\Stock;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<PortfolioTransaction>
 */
class PortfolioTransactionFactory extends Factory
{
    protected $model = PortfolioTransaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'portfolio_id' => Portfolio::factory(),
            'stock_id' => Stock::factory(),
            'type' => TransactionType::Buy->value,
            'quantity' => 100,
            'price' => 400,
            'commission' => 0,
            'other_fees' => 0,
            'realized_gain' => null,
            'transacted_on' => Carbon::today()->subYear()->toDateString(),
            'notes' => null,
        ];
    }

    public function buy(float $quantity, float $price, Carbon|string|null $on = null): static
    {
        return $this->state(fn (): array => [
            'type' => TransactionType::Buy->value,
            'quantity' => $quantity,
            'price' => $price,
        ])->on($on);
    }

    public function sell(float $quantity, float $price, Carbon|string|null $on = null): static
    {
        return $this->state(fn (): array => [
            'type' => TransactionType::Sell->value,
            'quantity' => $quantity,
            'price' => $price,
        ])->on($on);
    }

    public function on(Carbon|string|null $date): static
    {
        if ($date === null) {
            return $this;
        }

        return $this->state(fn (): array => [
            'transacted_on' => $date instanceof Carbon ? $date->toDateString() : $date,
        ]);
    }
}
