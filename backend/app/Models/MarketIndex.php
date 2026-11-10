<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketIndex extends Model
{
    use HasFactory;

    protected $table = 'market_indices';

    protected $fillable = [
        'name',
        'slug',
        'trade_date',
        'value',
        'change_amount',
        'change_percent',
        'total_turnover',
        'total_volume',
        'total_transactions',
        'advances',
        'declines',
        'unchanged',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'trade_date' => 'date',
            'value' => 'float',
            'change_amount' => 'float',
            'change_percent' => 'float',
            'total_turnover' => 'decimal:2',
            'total_volume' => 'integer',
            'total_transactions' => 'integer',
            'advances' => 'integer',
            'declines' => 'integer',
            'unchanged' => 'integer',
        ];
    }
}
