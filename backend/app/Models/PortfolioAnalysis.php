<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioAnalysis extends Model
{
    use HasFactory;

    protected $table = 'portfolio_analyses';

    protected $fillable = [
        'portfolio_id',
        'analysis_date',
        'health_score',
        'diversification_grade',
        'risk_level',
        'total_invested',
        'total_value',
        'unrealized_gain',
        'unrealized_gain_percent',
        'sector_allocation',
        'concentration_risks',
        'rebalancing_actions',
        'strengths',
        'weaknesses',
        'summary',
        'model',
        'input_tokens',
        'output_tokens',
    ];

    protected function casts(): array
    {
        return [
            'analysis_date' => 'date',
            'health_score' => 'integer',
            'total_invested' => 'decimal:2',
            'total_value' => 'decimal:2',
            'unrealized_gain' => 'decimal:2',
            'unrealized_gain_percent' => 'decimal:2',
            'sector_allocation' => 'array',
            'concentration_risks' => 'array',
            'rebalancing_actions' => 'array',
            'strengths' => 'array',
            'weaknesses' => 'array',
        ];
    }

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }
}
