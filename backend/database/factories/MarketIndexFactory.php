<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MarketIndex;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<MarketIndex>
 *
 * `slug` is the join key the benchmark reads on — `market_indices` is unique on
 * (slug, trade_date) and `config('nepse.benchmark.market_index_slug')` is a
 * slug, not a display name — so it is set explicitly rather than derived from a
 * random name.
 */
class MarketIndexFactory extends Factory
{
    protected $model = MarketIndex::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'NEPSE Index',
            'slug' => 'nepse-index',
            'trade_date' => Carbon::today()->toDateString(),
            'value' => fake()->randomFloat(4, 1500, 3000),
            'change_amount' => null,
            'change_percent' => null,
            // Not published anywhere crawlable, so genuinely null rather than 0.
            'total_turnover' => null,
            'total_volume' => null,
            'total_transactions' => null,
            'advances' => null,
            'declines' => null,
            'unchanged' => null,
            'source' => 'test',
        ];
    }

    public function named(string $name): static
    {
        return $this->state(fn (): array => [
            'name' => $name,
            'slug' => Str::slug($name),
        ]);
    }

    public function slug(string $slug): static
    {
        return $this->state(fn (): array => ['slug' => $slug]);
    }

    public function on(Carbon|string $date, ?float $value = null): static
    {
        return $this->state(function () use ($date, $value): array {
            $state = ['trade_date' => $date instanceof Carbon ? $date->toDateString() : $date];

            if ($value !== null) {
                $state['value'] = $value;
            }

            return $state;
        });
    }
}
