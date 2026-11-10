<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Portfolio extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'currency',
        'cash_in_hand',
        'bank_balance',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'cash_in_hand' => 'decimal:2',
            'bank_balance' => 'decimal:2',
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function holdings(): HasMany
    {
        return $this->hasMany(PortfolioHolding::class);
    }

    /** Positions that are actually open — sold-out rows are kept for history. */
    public function openHoldings(): HasMany
    {
        return $this->hasMany(PortfolioHolding::class)->where('quantity', '>', 0);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PortfolioTransaction::class);
    }

    public function cashTransactions(): HasMany
    {
        return $this->hasMany(CashTransaction::class);
    }

    /**
     * Interest-bearing placements — money in a fixed deposit rather than held
     * as idle cash. Deliberately not part of investableCash(): it is committed
     * until maturity, so offering it to the buy-recommendation sizer would
     * propose trades the money cannot fund.
     */
    public function cashPlacements(): HasMany
    {
        return $this->hasMany(CashPlacement::class);
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(PortfolioAnalysis::class);
    }

    public function latestAnalysis(): HasOne
    {
        return $this->hasOne(PortfolioAnalysis::class)->latestOfMany('analysis_date');
    }

    public function recommendationRuns(): HasMany
    {
        return $this->hasMany(BuyRecommendationRun::class);
    }

    public function latestRecommendationRun(): HasOne
    {
        return $this->hasOne(BuyRecommendationRun::class)->latestOfMany('analysis_date');
    }

    /**
     * Total cash available to deploy into new positions.
     */
    public function investableCash(): float
    {
        return round((float) $this->cash_in_hand + (float) $this->bank_balance, 2);
    }
}
