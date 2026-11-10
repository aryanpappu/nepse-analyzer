<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Sector;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Sector>
 */
class SectorFactory extends Factory
{
    protected $model = Sector::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // `name` and `slug` are both unique, so they are derived from one
        // unique source rather than generated independently.
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => null,
        ];
    }

    /**
     * A sector under a fixed name — used when a test needs the sector's slug to
     * line up with a `market_indices` row.
     */
    public function named(string $name): static
    {
        return $this->state(fn (): array => [
            'name' => $name,
            'slug' => Str::slug($name),
        ]);
    }
}
