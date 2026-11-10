<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CashPlacementKind;
use App\Enums\CashPlacementStatus;
use App\Enums\CompoundingBasis;
use App\Enums\PayoutHandling;
use App\Services\Portfolio\CashPlacementService;
use App\Services\Portfolio\InterestAccrualCalculator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money parked outside the equity portfolio — a bank fixed deposit today,
 * with room for pledged-shares collateral later via `kind`.
 *
 * Interest arithmetic itself is not on this model: it stays in
 * {@see InterestAccrualCalculator}, a pure calculator
 * with no Eloquent dependency, so the maths can be unit tested without a
 * database. {@see CashPlacementService} is the seam
 * between the two.
 */
class CashPlacement extends Model
{
    use HasFactory;

    protected $fillable = [
        'portfolio_id',
        'kind',
        'institution',
        'reference',
        'principal',
        'interest_rate',
        'start_date',
        'maturity_date',
        'closed_on',
        'compounding_basis',
        'payout',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'kind' => CashPlacementKind::class,
            'compounding_basis' => CompoundingBasis::class,
            'payout' => PayoutHandling::class,
            'status' => CashPlacementStatus::class,
            'principal' => 'decimal:2',
            'interest_rate' => 'decimal:3',
            'start_date' => 'date',
            'maturity_date' => 'date',
            'closed_on' => 'date',
        ];
    }

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }
}
