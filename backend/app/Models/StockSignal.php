<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvestorProfile;
use App\Enums\RiskLevel;
use App\Enums\SignalType;
use App\Enums\TimeHorizon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class StockSignal extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_id',
        'investor_profile',
        'analysis_date',
        'signal',
        'confidence',
        'risk_level',
        'time_horizon',
        'reference_price',
        'entry_price',
        'entry_price_max',
        'exit_price',
        'stop_loss',
        'target_1',
        'target_2',
        'risk_reward_ratio',
        'expected_return_percent',
        'summary',
        'rationale',
        'indicators_snapshot',
        'model',
        'input_tokens',
        'output_tokens',
        'latency_ms',
    ];

    protected function casts(): array
    {
        return [
            'investor_profile' => InvestorProfile::class,
            'analysis_date' => 'date',
            'signal' => SignalType::class,
            'risk_level' => RiskLevel::class,
            'time_horizon' => TimeHorizon::class,
            'confidence' => 'integer',
            'reference_price' => 'decimal:2',
            'entry_price' => 'decimal:2',
            'entry_price_max' => 'decimal:2',
            'exit_price' => 'decimal:2',
            'stop_loss' => 'decimal:2',
            'target_1' => 'decimal:2',
            'target_2' => 'decimal:2',
            'risk_reward_ratio' => 'decimal:2',
            'expected_return_percent' => 'decimal:2',
            'rationale' => 'array',
            'indicators_snapshot' => 'array',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function scopeFresh(Builder $query, ?int $days = null): Builder
    {
        $days ??= (int) config('nepse.analysis.signal_ttl_days', 3);

        return $query->where('analysis_date', '>=', Carbon::today()->subDays($days)->toDateString());
    }

    public function scopeOfType(Builder $query, ?string $signal): Builder
    {
        $type = SignalType::tryFrom(strtolower((string) $signal));

        return $type === null ? $query : $query->where('signal', $type->value);
    }

    public function scopeMinConfidence(Builder $query, int|float|null $min): Builder
    {
        return $min === null ? $query : $query->where('confidence', '>=', (int) $min);
    }

    /**
     * A signal is stale once the market has moved on; the UI greys these out
     * rather than hiding them, so the age is part of the payload.
     */
    public function isStale(): bool
    {
        $ttl = (int) config('nepse.analysis.signal_ttl_days', 3);

        return $this->analysis_date->lt(Carbon::today()->subDays($ttl));
    }
}
