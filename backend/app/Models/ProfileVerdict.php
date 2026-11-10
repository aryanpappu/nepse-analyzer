<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvestorProfile;
use App\Enums\VerdictGrade;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileVerdict extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_id',
        'investor_profile',
        'analysis_date',
        'grade',
        'score',
        'reference_price',
        'entry_low',
        'entry_high',
        'stop_loss',
        'target_1',
        'target_2',
        'risk_reward',
        'price_in_zone',
        'scores',
        'reasons',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'investor_profile' => InvestorProfile::class,
            'grade' => VerdictGrade::class,
            'analysis_date' => 'date',
            'score' => 'float',
            'reference_price' => 'float',
            'entry_low' => 'float',
            'entry_high' => 'float',
            'stop_loss' => 'float',
            'target_1' => 'float',
            'target_2' => 'float',
            'risk_reward' => 'float',
            'price_in_zone' => 'boolean',
            'scores' => 'array',
            'reasons' => 'array',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function scopeForProfile(Builder $query, InvestorProfile|string|null $profile): Builder
    {
        if ($profile === null) {
            return $query;
        }

        return $query->where(
            'investor_profile',
            $profile instanceof InvestorProfile ? $profile->value : $profile,
        );
    }

    /**
     * Only the newest verdict per profile.
     *
     * A verdict is written per session, so without this every screen would show
     * the same scrip once for every day it has been scored.
     */
    public function scopeLatestPerProfile(Builder $query): Builder
    {
        return $query->whereIn('analysis_date', function ($sub): void {
            $sub->selectRaw('max(analysis_date)')
                ->from('profile_verdicts as inner_pv')
                ->whereColumn('inner_pv.stock_id', 'profile_verdicts.stock_id')
                ->whereColumn('inner_pv.investor_profile', 'profile_verdicts.investor_profile');
        });
    }

    public function scopeActionable(Builder $query): Builder
    {
        return $query->whereIn('grade', [VerdictGrade::StrongBuy->value, VerdictGrade::Buy->value]);
    }
}
