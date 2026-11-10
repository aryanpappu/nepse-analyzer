<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_id',
        'trade_date',
        'open',
        'high',
        'low',
        'close',
        'previous_close',
        'average_price',
        'change_amount',
        'change_percent',
        'volume',
        'turnover',
        'transactions',
        'fifty_two_week_high',
        'fifty_two_week_low',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'trade_date' => 'date',
            'open' => 'decimal:2',
            'high' => 'decimal:2',
            'low' => 'decimal:2',
            'close' => 'decimal:2',
            'previous_close' => 'decimal:2',
            'average_price' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'change_percent' => 'decimal:2',
            'volume' => 'integer',
            'turnover' => 'decimal:2',
            'transactions' => 'integer',
            'fifty_two_week_high' => 'decimal:2',
            'fifty_two_week_low' => 'decimal:2',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('trade_date', [$from, $to]);
    }

    public function scopeRecent(Builder $query, int $days): Builder
    {
        return $query->orderByDesc('trade_date')->limit($days);
    }
}
