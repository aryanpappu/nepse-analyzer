<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CashPlacementKind;
use App\Enums\CashPlacementStatus;
use App\Enums\CompoundingBasis;
use App\Enums\PayoutHandling;
use App\Models\CashPlacement;
use App\Models\Portfolio;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<CashPlacement>
 */
class CashPlacementFactory extends Factory
{
    protected $model = CashPlacement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'portfolio_id' => Portfolio::factory(),
            'kind' => CashPlacementKind::FixedDeposit->value,
            'institution' => 'Nabil Bank',
            'reference' => fake()->unique()->numerify('FD-#####'),
            'principal' => 100_000,
            'interest_rate' => 8.5,
            'start_date' => Carbon::today()->subDays(180)->toDateString(),
            'maturity_date' => Carbon::today()->addDays(185)->toDateString(),
            'closed_on' => null,
            'compounding_basis' => CompoundingBasis::Simple->value,
            'payout' => PayoutHandling::PaidOut->value,
            'status' => CashPlacementStatus::Active->value,
            'notes' => null,
        ];
    }

    public function reinvested(): static
    {
        return $this->state(fn (): array => ['payout' => PayoutHandling::Reinvested->value]);
    }

    public function matured(): static
    {
        return $this->state(fn (): array => [
            'status' => CashPlacementStatus::Matured->value,
            'maturity_date' => Carbon::today()->subDay()->toDateString(),
        ]);
    }

    public function withdrawn(?Carbon $closedOn = null): static
    {
        return $this->state(fn (): array => [
            'status' => CashPlacementStatus::Withdrawn->value,
            'closed_on' => ($closedOn ?? Carbon::today())->toDateString(),
        ]);
    }
}
