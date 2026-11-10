<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundNav extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_id',
        'nav_date',
        'nav',
        'market_price',
        'premium_discount_percent',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'nav_date' => 'date',
            'nav' => 'float',
            'market_price' => 'float',
            'premium_discount_percent' => 'float',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * Closed-end funds in Nepal normally trade below NAV; a persistent discount
     * is the norm rather than a signal on its own.
     */
    public function discountLabel(): ?string
    {
        if ($this->premium_discount_percent === null) {
            return null;
        }

        return match (true) {
            $this->premium_discount_percent <= -20 => 'deep discount',
            $this->premium_discount_percent < -5 => 'discount',
            $this->premium_discount_percent <= 5 => 'near NAV',
            $this->premium_discount_percent <= 20 => 'premium',
            default => 'high premium',
        };
    }
}
