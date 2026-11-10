<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuyRecommendation extends Model
{
    use HasFactory;

    protected $fillable = [
        'run_id',
        'stock_id',
        'rank',
        'action',
        'suggested_quantity',
        'suggested_price',
        'estimated_cost',
        'allocation_percent',
        'stop_loss',
        'target_price',
        'conviction',
        'risk_level',
        'time_horizon',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'suggested_quantity' => 'decimal:2',
            'suggested_price' => 'decimal:2',
            'estimated_cost' => 'decimal:2',
            'allocation_percent' => 'decimal:2',
            'stop_loss' => 'decimal:2',
            'target_price' => 'decimal:2',
            'conviction' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BuyRecommendationRun::class, 'run_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
