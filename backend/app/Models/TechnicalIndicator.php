<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalIndicator extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_id',
        'trade_date',
        'rsi_14',
        'macd',
        'macd_signal',
        'macd_histogram',
        'sma_20',
        'sma_50',
        'sma_200',
        'ema_12',
        'ema_26',
        'bollinger_upper',
        'bollinger_middle',
        'bollinger_lower',
        'atr_14',
        'volume_sma_20',
        'trend',
        'trend_strength',
    ];

    protected function casts(): array
    {
        return [
            'trade_date' => 'date',
            'rsi_14' => 'float',
            'macd' => 'float',
            'macd_signal' => 'float',
            'macd_histogram' => 'float',
            'sma_20' => 'float',
            'sma_50' => 'float',
            'sma_200' => 'float',
            'ema_12' => 'float',
            'ema_26' => 'float',
            'bollinger_upper' => 'float',
            'bollinger_middle' => 'float',
            'bollinger_lower' => 'float',
            'atr_14' => 'float',
            'volume_sma_20' => 'float',
            'trend_strength' => 'float',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * Human readable RSI zone — used in both the API payload and the prompt so
     * the model and the UI describe the same thing the same way.
     */
    public function rsiZone(): ?string
    {
        if ($this->rsi_14 === null) {
            return null;
        }

        return match (true) {
            $this->rsi_14 >= 70 => 'overbought',
            $this->rsi_14 <= 30 => 'oversold',
            $this->rsi_14 >= 55 => 'bullish',
            $this->rsi_14 <= 45 => 'bearish',
            default => 'neutral',
        };
    }
}
