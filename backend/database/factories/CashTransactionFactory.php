<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CashTransaction;
use App\Models\Portfolio;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<CashTransaction>
 *
 * Writes a ledger row directly, which deliberately does *not* move the
 * portfolio's balance columns — the ledger is the source of truth and
 * `CashLedger` is what keeps the two in step. Use this for rows a test only
 * needs to exist (ownership checks, listing shape); build balances that are
 * asserted on through the API or `CashLedger` instead.
 */
class CashTransactionFactory extends Factory
{
    protected $model = CashTransaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'portfolio_id' => Portfolio::factory(),
            'type' => 'deposit',
            'account' => CashTransaction::ACCOUNT_BANK,
            // Signed: positive credits the account, negative debits it.
            'amount' => 10_000,
            'balance_after' => 10_000,
            'reference' => null,
            'notes' => null,
            'transacted_on' => Carbon::today()->toDateString(),
        ];
    }

    public function deposit(float $amount, string $account = CashTransaction::ACCOUNT_BANK): static
    {
        return $this->state(fn (): array => [
            'type' => 'deposit',
            'account' => $account,
            'amount' => abs($amount),
            'balance_after' => abs($amount),
        ]);
    }

    public function withdrawal(float $amount, string $account = CashTransaction::ACCOUNT_BANK): static
    {
        return $this->state(fn (): array => [
            'type' => 'withdrawal',
            'account' => $account,
            'amount' => -abs($amount),
            'balance_after' => null,
        ]);
    }

    public function on(Carbon|string $date): static
    {
        return $this->state(fn (): array => [
            'transacted_on' => $date instanceof Carbon ? $date->toDateString() : $date,
        ]);
    }

    public function voided(?string $reason = null): static
    {
        return $this->state(fn (): array => [
            'voided_at' => Carbon::now(),
            'void_reason' => $reason ?? 'Entered in error.',
        ]);
    }
}
