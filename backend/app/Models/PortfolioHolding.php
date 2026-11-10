<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class PortfolioHolding extends Model
{
    use HasFactory;

    protected $fillable = [
        'portfolio_id',
        'stock_id',
        'quantity',
        'average_cost',
        'realized_gain',
        'total_fees',
        'first_purchased_on',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'average_cost' => 'decimal:4',
            'realized_gain' => 'decimal:2',
            'total_fees' => 'decimal:2',
            'first_purchased_on' => 'date',
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

    // ------------------------------------------------------------------
    // Derived values. Computed on read from the latest close so they are
    // never stale, and never written to the table.
    // ------------------------------------------------------------------

    public function investedAmount(): float
    {
        return round((float) $this->quantity * (float) $this->average_cost, 2);
    }

    /**
     * The spot mark: `stocks.last_price`.
     *
     * This is the freshest price the app holds and, for a MeroShare-imported
     * stub with no row in `stock_prices` or `fund_navs`, the only one. It is
     * also *not* a historical mark: it carries one date
     * ({@see markAsOf()}) and cannot value any boundary before it. Anything
     * pricing a past date goes through `PortfolioTimeSeries`, which resolves
     * the two sources via `MarkSourceReconciler`; this accessor stays as the
     * live mark so the summary figures it feeds do not move.
     */
    public function currentPrice(): ?float
    {
        $price = $this->stock?->last_price;

        return $price === null ? null : (float) $price;
    }

    /**
     * The date `currentPrice()` belongs to — `stocks.last_traded_on`.
     *
     * Null means the price is undated, which makes it unusable for marking any
     * date at all. Nothing should treat a price as current without checking
     * how old it is.
     */
    public function markAsOf(): ?Carbon
    {
        $date = $this->stock?->last_traded_on;

        return $date === null ? null : $date->copy()->startOfDay();
    }

    /**
     * Calendar days between the mark's date and $asOf (default today). Null
     * when the mark has no date, which is a different answer from zero.
     */
    public function markStaleDays(?Carbon $asOf = null): ?int
    {
        $markedOn = $this->markAsOf();

        if ($markedOn === null) {
            return null;
        }

        // Carbon returns a float here and an unrounded float in an integer
        // position has crashed a date calculation in this codebase before.
        return (int) $markedOn->diffInDays(($asOf ?? Carbon::today())->copy()->startOfDay());
    }

    public function currentValue(): ?float
    {
        $price = $this->currentPrice();

        return $price === null ? null : round((float) $this->quantity * $price, 2);
    }

    public function unrealizedGain(): ?float
    {
        $value = $this->currentValue();

        return $value === null ? null : round($value - $this->investedAmount(), 2);
    }

    public function unrealizedGainPercent(): ?float
    {
        $invested = $this->investedAmount();
        $gain = $this->unrealizedGain();

        if ($gain === null || $invested <= 0.0) {
            return null;
        }

        return round($gain / $invested * 100, 2);
    }
}
