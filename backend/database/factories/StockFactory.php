<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Sector;
use App\Models\Stock;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Stock>
 */
class StockFactory extends Factory
{
    protected $model = Stock::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $close = fake()->randomFloat(2, 100, 2000);

        return [
            // NEPSE tickers are always stored upper case and are unique.
            'symbol' => strtoupper(fake()->unique()->bothify('???##')),
            'name' => fake()->company(),
            'sector_id' => null,
            'instrument_type' => 'equity',
            'listed_shares' => 10_000_000,
            'paid_up_value' => 100,
            'market_capitalization' => round($close * 10_000_000, 2),
            'last_price' => $close,
            'previous_close' => $close,
            'change_percent' => 0,
            'last_traded_on' => Carbon::today()->toDateString(),
            'is_active' => true,
        ];
    }

    public function symbol(string $symbol): static
    {
        return $this->state(fn (): array => ['symbol' => strtoupper($symbol)]);
    }

    public function inSector(Sector|int $sector): static
    {
        return $this->state(fn (): array => [
            'sector_id' => $sector instanceof Sector ? $sector->id : $sector,
        ]);
    }

    public function price(float $lastPrice): static
    {
        return $this->state(fn (): array => ['last_price' => $lastPrice]);
    }

    /**
     * A scrip with no stored close at all — the "cannot be priced honestly"
     * case the valuation service reports rather than treating cost as value.
     */
    public function unpriced(): static
    {
        return $this->state(fn (): array => [
            'last_price' => null,
            'previous_close' => null,
            'change_percent' => null,
            'last_traded_on' => null,
        ]);
    }

    public function mutualFund(): static
    {
        return $this->state(fn (): array => [
            'instrument_type' => 'mutual_fund',
            'publishes_nav' => true,
        ]);
    }
}
