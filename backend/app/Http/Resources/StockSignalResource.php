<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StockSignal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockSignal
 */
class StockSignalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'symbol' => $this->whenLoaded('stock', fn () => $this->stock->symbol),
            'name' => $this->whenLoaded('stock', fn () => $this->stock->name),
            'sector' => $this->whenLoaded('stock', fn () => $this->stock->sector?->name),

            'analysis_date' => $this->analysis_date->toDateString(),
            'signal' => $this->signal->value,
            // Which lens produced this: a scrip can carry one signal per
            // profile for the same session.
            'investor_profile' => $this->investor_profile?->value,
            'investor_profile_label' => $this->investor_profile?->shortLabel(),
            'signal_label' => $this->signal->label(),
            'confidence' => $this->confidence,
            'risk_level' => $this->risk_level->value,
            'time_horizon' => $this->time_horizon->value,
            'time_horizon_label' => $this->time_horizon->label(),

            'reference_price' => (float) $this->reference_price,
            'entry_price' => $this->float($this->entry_price),
            'entry_price_max' => $this->float($this->entry_price_max),
            'exit_price' => $this->float($this->exit_price),
            'stop_loss' => $this->float($this->stop_loss),
            'target_1' => $this->float($this->target_1),
            'target_2' => $this->float($this->target_2),
            'risk_reward_ratio' => $this->float($this->risk_reward_ratio),
            'expected_return_percent' => $this->float($this->expected_return_percent),

            'summary' => $this->summary,
            'rationale' => $this->rationale,
            'indicators_snapshot' => $this->indicators_snapshot,

            // Surfaced so the UI can grey out an old call rather than hide it.
            'is_stale' => $this->isStale(),
            'model' => $this->model,
            'generated_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function float(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
