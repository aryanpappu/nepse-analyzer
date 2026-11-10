<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Stock
 */
class StockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'symbol' => $this->symbol,
            'name' => $this->name,
            'sector' => $this->whenLoaded('sector', fn () => $this->sector?->name),
            'sector_slug' => $this->whenLoaded('sector', fn () => $this->sector?->slug),
            'instrument_type' => $this->instrument_type,

            'last_price' => $this->float($this->last_price),
            'previous_close' => $this->float($this->previous_close),
            'change_amount' => $this->last_price !== null && $this->previous_close !== null
                ? round((float) $this->last_price - (float) $this->previous_close, 2)
                : null,
            'change_percent' => $this->float($this->change_percent),
            'last_traded_on' => $this->last_traded_on?->toDateString(),

            'market_capitalization' => $this->float($this->market_capitalization),
            'listed_shares' => $this->float($this->listed_shares),

            // Only present when the caller asked for them, so the list endpoint
            // stays cheap.
            'volume' => $this->whenLoaded('latestPrice', fn () => (int) $this->latestPrice?->volume),
            'turnover' => $this->whenLoaded('latestPrice', fn () => $this->float($this->latestPrice?->turnover)),

            'signal' => $this->whenLoaded('latestSignal', fn () => $this->latestSignal === null ? null : [
                'signal' => $this->latestSignal->signal->value,
                'confidence' => $this->latestSignal->confidence,
                'risk_level' => $this->latestSignal->risk_level->value,
                'analysis_date' => $this->latestSignal->analysis_date->toDateString(),
                'is_stale' => $this->latestSignal->isStale(),
            ]),

            'indicators' => $this->whenLoaded('latestIndicator', fn () => $this->latestIndicator === null ? null : [
                'rsi_14' => $this->latestIndicator->rsi_14,
                'rsi_zone' => $this->latestIndicator->rsiZone(),
                'trend' => $this->latestIndicator->trend,
                'trend_strength' => $this->latestIndicator->trend_strength,
            ]),

            'is_active' => $this->is_active,
        ];
    }

    private function float(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
