<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvestorProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Stock extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol',
        'external_id',
        'name',
        'sector_id',
        'instrument_type',
        'listed_shares',
        'paid_up_value',
        'market_capitalization',
        'last_price',
        'previous_close',
        'change_percent',
        'last_traded_on',
        'is_active',
        'delisted_at',
        'classification_source',
        'publishes_nav',
        'nav_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'external_id' => 'integer',
            'listed_shares' => 'decimal:2',
            'paid_up_value' => 'decimal:2',
            'market_capitalization' => 'decimal:2',
            'last_price' => 'decimal:2',
            'previous_close' => 'decimal:2',
            'change_percent' => 'decimal:2',
            'last_traded_on' => 'date',
            'is_active' => 'boolean',
            'delisted_at' => 'datetime',
            'publishes_nav' => 'boolean',
            'nav_checked_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(StockPrice::class);
    }

    public function indicators(): HasMany
    {
        return $this->hasMany(TechnicalIndicator::class);
    }

    public function fundamentals(): HasMany
    {
        return $this->hasMany(StockFundamental::class);
    }

    public function signals(): HasMany
    {
        return $this->hasMany(StockSignal::class);
    }

    public function fundamentalAnalyses(): HasMany
    {
        return $this->hasMany(FundamentalAnalysis::class);
    }

    public function holdings(): HasMany
    {
        return $this->hasMany(PortfolioHolding::class);
    }

    /**
     * Latest row helpers — `latestOfMany` keeps the listing endpoint to a
     * single extra query instead of one per stock.
     */
    public function latestPrice(): HasOne
    {
        return $this->hasOne(StockPrice::class)->latestOfMany('trade_date');
    }

    public function latestIndicator(): HasOne
    {
        return $this->hasOne(TechnicalIndicator::class)->latestOfMany('trade_date');
    }

    /**
     * The most recent signal, whichever profile produced it.
     *
     * A scrip can now hold one signal per investor profile for the same
     * session, so this is "the latest AI take" rather than "the latest take for
     * my profile" — which is what the listings want. Use signalsForProfile()
     * when the lens matters.
     */
    public function latestSignal(): HasOne
    {
        return $this->hasOne(StockSignal::class)->latestOfMany(['analysis_date', 'id']);
    }

    /**
     * @return HasMany<StockSignal, $this>
     */
    public function signalsForProfile(InvestorProfile|string $profile): HasMany
    {
        return $this->signals()
            ->where('investor_profile', $profile instanceof InvestorProfile ? $profile->value : $profile)
            ->orderByDesc('analysis_date');
    }

    public function latestFundamental(): HasOne
    {
        return $this->hasOne(StockFundamental::class)->latestOfMany();
    }

    public function latestFundamentalAnalysis(): HasOne
    {
        return $this->hasOne(FundamentalAnalysis::class)->latestOfMany('analysis_date');
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSymbol(Builder $query, string $symbol): Builder
    {
        return $query->where('symbol', strtoupper(trim($symbol)));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($term): void {
            $inner->where('symbol', 'like', $term.'%')
                ->orWhere('name', 'like', '%'.$term.'%');
        });
    }

    public function scopeInSector(Builder $query, int|string|null $sector): Builder
    {
        if ($sector === null || $sector === '') {
            return $query;
        }

        if (is_numeric($sector)) {
            return $query->where('sector_id', (int) $sector);
        }

        return $query->whereHas('sector', fn (Builder $q) => $q->where('slug', $sector));
    }

    // ------------------------------------------------------------------
    // Route model binding by symbol, not id.
    // ------------------------------------------------------------------

    public function getRouteKeyName(): string
    {
        return 'symbol';
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->where($field ?? $this->getRouteKeyName(), strtoupper(trim((string) $value)))
            ->first();
    }
}
