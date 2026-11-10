<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BuyRecommendationRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'portfolio_id',
        'investor_profile',
        'analysis_date',
        'investable_cash',
        'allocated_amount',
        'reserve_amount',
        'strategy_summary',
        'avoid_list',
        'model',
        'input_tokens',
        'output_tokens',
    ];

    protected function casts(): array
    {
        return [
            'analysis_date' => 'date',
            'investable_cash' => 'decimal:2',
            'allocated_amount' => 'decimal:2',
            'reserve_amount' => 'decimal:2',
            'avoid_list' => 'array',
        ];
    }

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(BuyRecommendation::class, 'run_id')->orderBy('rank');
    }
}
