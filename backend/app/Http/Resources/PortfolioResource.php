<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Portfolio;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Portfolio
 */
class PortfolioResource extends JsonResource
{
    /**
     * @param  array<string, mixed>|null  $summary
     */
    public function __construct($resource, private readonly ?array $summary = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'currency' => $this->currency,
            'is_default' => $this->is_default,

            'cash_in_hand' => (float) $this->cash_in_hand,
            'bank_balance' => (float) $this->bank_balance,
            'investable_cash' => $this->investableCash(),

            'summary' => $this->when($this->summary !== null, fn () => $this->summary),

            'holdings_count' => $this->whenCounted('openHoldings'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
