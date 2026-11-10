<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Stock;
use App\Models\StockPrice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<StockPrice>
 */
class StockPriceFactory extends Factory
{
    protected $model = StockPrice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $close = fake()->randomFloat(2, 100, 2000);

        return [
            'stock_id' => Stock::factory(),
            'trade_date' => Carbon::today()->toDateString(),
            'open' => $close,
            'high' => $close,
            'low' => $close,
            'close' => $close,
            'previous_close' => $close,
            'average_price' => $close,
            'change_amount' => 0,
            'change_percent' => 0,
            'volume' => 1000,
            'turnover' => round($close * 1000, 2),
            'transactions' => 10,
            'source' => 'test',
        ];
    }

    public function on(Carbon|string $date): static
    {
        return $this->state(fn (): array => [
            'trade_date' => $date instanceof Carbon ? $date->toDateString() : $date,
        ]);
    }

    public function close(float $close): static
    {
        return $this->state(fn (): array => [
            'open' => $close,
            'high' => $close,
            'low' => $close,
            'close' => $close,
            'average_price' => $close,
        ]);
    }
}
