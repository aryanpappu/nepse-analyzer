<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Portfolio;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Portfolio>
 */
class PortfolioFactory extends Factory
{
    protected $model = Portfolio::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            // Unique per user, so keep it unique outright.
            'name' => Str::title(fake()->unique()->words(2, true)).' Portfolio',
            'description' => null,
            'currency' => 'NPR',
            // Balances start at zero: they are a function of the cash ledger,
            // so a test that wants money in an account should put it there
            // through the ledger rather than seeding a figure with no history.
            'cash_in_hand' => 0,
            'bank_balance' => 0,
            'is_default' => false,
        ];
    }

    public function forUser(User|int $user): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user instanceof User ? $user->id : $user,
        ]);
    }

    public function default(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }

    /**
     * Opening balances written straight onto the columns. Only for tests that
     * do not assert on the ledger itself.
     */
    public function withBalances(float $cashInHand, float $bankBalance): static
    {
        return $this->state(fn (): array => [
            'cash_in_hand' => $cashInHand,
            'bank_balance' => $bankBalance,
        ]);
    }
}
