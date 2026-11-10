<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Scraper\CorporateActionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A dated corporate event — book close, general meeting, dividend proposal,
 * rights or public issue.
 *
 * The scopes are the point of the model. Nothing here stores whether an event
 * is upcoming: the source's own status flag is discarded at the boundary
 * because it is computed against ShareSansar's clock, so "upcoming" is a
 * question asked of the dates at the moment someone asks it, against a date
 * the caller supplies. That keeps the row honest for as long as it is stored.
 */
class CorporateAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_id',
        'type',
        'feed',
        'source',
        'external_key',
        'book_close_on',
        'event_on',
        'closes_on',
        'final_on',
        'listing_on',
        'announced_on',
        'distribution_on',
        'approved_on',
        'ratio',
        'units',
        'issue_price',
        'cash_dividend_percent',
        'bonus_percent',
        'total_dividend_percent',
        'fiscal_period',
        'issue_manager',
        'title',
        'venue',
        'agenda',
        'published_on',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'book_close_on' => 'date',
            'event_on' => 'date',
            'closes_on' => 'date',
            'final_on' => 'date',
            'listing_on' => 'date',
            'announced_on' => 'date',
            'distribution_on' => 'date',
            'approved_on' => 'date',
            'published_on' => 'date',
            'units' => 'float',
            'issue_price' => 'float',
            'cash_dividend_percent' => 'float',
            'bonus_percent' => 'float',
            'total_dividend_percent' => 'float',
            'raw' => 'array',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * Events whose book close falls in [$from, $from + $days].
     *
     * The alert query: "NABIL book close in 3 days". $from is required rather
     * than defaulted to today so the caller states which date it means — a
     * scheduled job runs against the session it is processing, not the wall
     * clock of the machine it happens to run on.
     */
    public function scopeBookClosingBetween(Builder $query, Carbon $from, int $days): Builder
    {
        return $query
            ->whereNotNull('book_close_on')
            ->whereBetween('book_close_on', [
                $from->copy()->startOfDay()->toDateString(),
                $from->copy()->startOfDay()->addDays(max(0, $days))->toDateString(),
            ]);
    }

    /**
     * Issues open for application on $on — opened on or before it, and either
     * not yet closed or closing on or after it.
     */
    public function scopeOpenIssuesOn(Builder $query, Carbon $on): Builder
    {
        $date = $on->copy()->startOfDay()->toDateString();

        return $query
            ->whereIn('type', [
                CorporateActionType::RIGHTS,
                CorporateActionType::IPO,
                CorporateActionType::FPO,
                CorporateActionType::IPO_LOCAL,
                CorporateActionType::IPO_MIGRANT,
                CorporateActionType::IPO_QII,
                CorporateActionType::MUTUAL_FUND,
                CorporateActionType::DEBENTURE,
            ])
            ->whereNotNull('event_on')
            ->whereDate('event_on', '<=', $date)
            ->where(static fn (Builder $q): Builder => $q
                ->whereNull('closes_on')
                ->orWhereDate('closes_on', '>=', $date));
    }

    /** General meetings on or after $on. */
    public function scopeMeetingsFrom(Builder $query, Carbon $on): Builder
    {
        return $query
            ->whereIn('type', [CorporateActionType::AGM, CorporateActionType::SGM])
            ->whereNotNull('event_on')
            ->whereDate('event_on', '>=', $on->copy()->startOfDay()->toDateString());
    }
}
