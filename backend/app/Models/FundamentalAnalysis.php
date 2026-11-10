<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundamentalAnalysis extends Model
{
    use HasFactory;

    protected $table = 'fundamental_analyses';

    protected $fillable = [
        'stock_id',
        'analysis_date',
        'valuation',
        'quality_score',
        'financial_health_score',
        'dividend_sustainability_score',
        'fair_value_low',
        'fair_value_high',
        'sector_pe_comparison',
        'strengths',
        'concerns',
        'metrics_snapshot',
        'summary',
        'model',
        'input_tokens',
        'output_tokens',
    ];

    protected function casts(): array
    {
        return [
            'analysis_date' => 'date',
            'quality_score' => 'integer',
            'financial_health_score' => 'integer',
            'dividend_sustainability_score' => 'integer',
            'fair_value_low' => 'decimal:2',
            'fair_value_high' => 'decimal:2',
            'sector_pe_comparison' => 'decimal:4',
            'strengths' => 'array',
            'concerns' => 'array',
            'metrics_snapshot' => 'array',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
