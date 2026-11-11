<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'acquisition_channel',
        'channel_confirmed_at',
        'channel_basis',
        'portfolio_id',
        'stock_id',
        'type',
        'quantity',
        'price',
        'commission',
        'other_fees',
        'realized_gain',
        'transacted_on',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'channel_confirmed_at' => 'datetime',
            'type' => TransactionType::class,
            'quantity' => 'decimal:2',
            'price' => 'decimal:4',
            'commission' => 'decimal:2',
            'other_fees' => 'decimal:2',
            'realized_gain' => 'decimal:2',
            'transacted_on' => 'date',
        ];
    }

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * Rows that brought units *in*, which is the only set an acquisition
     * channel can be set on — a sale has no channel to speak of.
     *
     * A scope rather than a repeated `where`, because the IPO/Traded screen and
     * the getting-started checklist both count this set and were briefly
     * disagreeing about it: the screen listed 65 parcels while the checklist
     * reported 95 outstanding, so clearing every row on screen would have left
     * the checklist still demanding work that did not exist.
     *
     * @param  Builder<PortfolioTransaction>  $query
     * @return Builder<PortfolioTransaction>
     */
    public function scopeAcquisitions(Builder $query): Builder
    {
        return $query->where('type', '!=', TransactionType::Sell->value);
    }

    public function fees(): float
    {
        return round((float) $this->commission + (float) $this->other_fees, 2);
    }

    /**
     * Cash impact of the trade. Buys cost price*qty plus fees; sells return
     * price*qty minus fees.
     */
    public function grossAmount(): float
    {
        return round((float) $this->quantity * (float) $this->price, 2);
    }

    public function netCashImpact(): float
    {
        return match ($this->type) {
            TransactionType::Buy, TransactionType::Rights => -round($this->grossAmount() + $this->fees(), 2),
            TransactionType::Sell => round($this->grossAmount() - $this->fees(), 2),
            TransactionType::Dividend => $this->dividendCash(),
            TransactionType::Bonus => 0.0,
        };
    }

    /**
     * Cash a dividend row paid, net of fees and withholding.
     *
     * Dividends are usually entered as a lump sum in `quantity` with a zero
     * price; read as `quantity × price` that is a dividend of nothing. Both the
     * position replay and the cash settlement read this one method so they
     * cannot disagree about what a dividend was worth.
     */
    public function dividendCash(): float
    {
        $gross = abs((float) $this->price) < 1e-9 ? (float) $this->quantity : $this->grossAmount();

        return round($gross - $this->fees(), 2);
    }
}
