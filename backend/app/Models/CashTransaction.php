<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashTransaction extends Model
{
    use HasFactory;

    public const ACCOUNT_CASH = 'cash';

    public const ACCOUNT_BANK = 'bank';

    protected $fillable = [
        'portfolio_id',
        'portfolio_transaction_id',
        'type',
        'account',
        'amount',
        'balance_after',
        'reference',
        'notes',
        'transacted_on',
        'voided_at',
        'void_reason',
        'corrected_at',
        'original_values',
        'transfer_group_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'transacted_on' => 'date',
            'voided_at' => 'datetime',
            'corrected_at' => 'datetime',
            'original_values' => 'array',
        ];
    }

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    /** Whether this row was voided (soft-deleted) rather than deleted outright. */
    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /** Whether this row was amended after it was first recorded. */
    public function isCorrected(): bool
    {
        return $this->corrected_at !== null;
    }

    /** Exclude voided rows — the ones that should not count toward a balance. */
    public function scopeNotVoided(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    /** @return list<string> */
    public static function accounts(): array
    {
        return [self::ACCOUNT_CASH, self::ACCOUNT_BANK];
    }

    /** Column on `portfolios` that this account maps to. */
    public static function balanceColumn(string $account): string
    {
        return $account === self::ACCOUNT_BANK ? 'bank_balance' : 'cash_in_hand';
    }
}
